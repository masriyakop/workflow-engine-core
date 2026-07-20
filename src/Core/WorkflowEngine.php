<?php

namespace SolutionForest\WorkflowEngine\Core;

use SolutionForest\WorkflowEngine\Actions\HumanTaskAction;
use SolutionForest\WorkflowEngine\Contracts\EventDispatcher;
use SolutionForest\WorkflowEngine\Contracts\StorageAdapter;
use SolutionForest\WorkflowEngine\Events\WorkflowCancelledEvent;
use SolutionForest\WorkflowEngine\Events\WorkflowStartedEvent;
use SolutionForest\WorkflowEngine\Exceptions\InvalidWorkflowDefinitionException;
use SolutionForest\WorkflowEngine\Exceptions\InvalidWorkflowStateException;
use SolutionForest\WorkflowEngine\Exceptions\WorkflowInstanceNotFoundException;

/**
 * Core workflow engine for managing workflow lifecycle and execution.
 *
 * The WorkflowEngine is the central component that orchestrates workflow
 * execution, state management, and event dispatching. It provides a clean
 * API for starting, resuming, and managing workflow instances.
 *
 *
 * @example Basic workflow execution
 * ```php
 * $engine = new WorkflowEngine($storageAdapter, $eventDispatcher);
 *
 * // Start a new workflow
 * $instanceId = $engine->start('user-onboarding', [
 *     'name' => 'User Onboarding',
 *     'steps' => [
 *         ['id' => 'welcome', 'action' => SendWelcomeEmailAction::class],
 *         ['id' => 'profile', 'action' => CreateProfileAction::class],
 *     ]
 * ], ['user_id' => 123]);
 *
 * // Resume execution later
 * $instance = $engine->resume($instanceId);
 * ```
 * @example With dependency injection
 * ```php
 * // In a Laravel service provider
 * $this->app->singleton(WorkflowEngine::class, function ($app) {
 *     return new WorkflowEngine(
 *         $app->make(StorageAdapter::class),
 *         $app->make(EventDispatcher::class)
 *     );
 * });
 * ```
 */
class WorkflowEngine
{
    /**
     * The definition parser for processing workflow definitions.
     */
    private readonly DefinitionParser $parser;

    /**
     * The state manager for persisting workflow state.
     */
    private readonly StateManager $stateManager;

    /**
     * The executor for running workflow steps.
     */
    private readonly Executor $executor;

    /**
     * Create a new workflow engine instance.
     *
     * @param StorageAdapter $storage The storage adapter for persisting workflow data
     * @param EventDispatcher|null $eventDispatcher Optional event dispatcher for workflow events
     *
     * @throws \InvalidArgumentException If the storage adapter is not properly configured
     */
    public function __construct(
        private readonly StorageAdapter $storage,
        private readonly ?EventDispatcher $eventDispatcher = null
    ) {
        $this->parser = new DefinitionParser;
        $this->stateManager = new StateManager($storage);
        $this->executor = new Executor($this->stateManager, $eventDispatcher);

        // If no event dispatcher is provided, we'll use a fallback approach
        if ($this->eventDispatcher === null) {
            // We'll handle this case in the methods that use the event dispatcher
        }
    }

    /**
     * Start a new workflow instance with the given definition and context.
     *
     * Creates a new workflow instance, saves it to storage, dispatches a start event,
     * and begins execution of the first step.
     *
     * @param string $workflowId Unique identifier for this workflow instance
     * @param array<string, mixed> $definition The workflow definition containing steps and configuration
     * @param array<string, mixed> $context Initial context data for the workflow
     * @return string The workflow instance ID
     *
     * @throws InvalidWorkflowDefinitionException If the workflow definition is invalid
     * @throws \RuntimeException If the workflow cannot be started due to system issues
     *
     * @example Starting a simple workflow
     * ```php
     * $instanceId = $engine->start('order-processing', [
     *     'name' => 'Order Processing',
     *     'steps' => [
     *         ['id' => 'validate', 'action' => ValidateOrderAction::class],
     *         ['id' => 'payment', 'action' => ProcessPaymentAction::class],
     *         ['id' => 'fulfill', 'action' => FulfillOrderAction::class],
     *     ]
     * ], [
     *     'order_id' => 12345,
     *     'customer_id' => 67890
     * ]);
     * ```
     */
    public function start(string $workflowId, array $definition, array $context = []): string
    {
        // Parse definition
        $workflowDef = $this->parser->parse($definition);

        // Create instance
        $instance = new WorkflowInstance(
            id: $workflowId,
            definition: $workflowDef,
            state: WorkflowState::PENDING,
            data: $context,
            createdAt: new \DateTime,
            updatedAt: new \DateTime
        );

        // Save initial state
        $this->stateManager->save($instance);

        // Dispatch start event
        $this->dispatchEvent(new WorkflowStartedEvent(
            $instance,
            $context
        ));

        // Execute first step
        $this->executor->execute($instance);

        return $instance->getId();
    }

    /**
     * Resume execution of an existing workflow instance.
     *
     * Loads the workflow instance from storage and continues execution
     * from where it left off. Only works for workflows in PENDING or FAILED state.
     *
     * @param string $instanceId The workflow instance ID to resume
     * @return WorkflowInstance The resumed workflow instance
     *
     * @throws WorkflowInstanceNotFoundException If the workflow instance doesn't exist
     * @throws InvalidWorkflowStateException If the workflow cannot be resumed (e.g., already completed)
     * @throws \RuntimeException If the workflow cannot be resumed due to system issues
     *
     * @example Resuming a workflow
     * ```php
     * try {
     *     $instance = $engine->resume('workflow-123');
     *     echo "Workflow resumed, current state: " . $instance->getState()->value;
     * } catch (InvalidWorkflowStateException $e) {
     *     echo "Cannot resume: " . $e->getUserMessage();
     * }
     * ```
     */
    public function resume(string $instanceId): WorkflowInstance
    {
        $instance = $this->stateManager->load($instanceId);

        if ($instance->getState() === WorkflowState::COMPLETED) {
            throw InvalidWorkflowStateException::cannotResumeCompleted($instanceId);
        }

        $this->executor->execute($instance);

        return $instance;
    }

    /**
     * Complete a human / approval task and continue the workflow.
     *
     * @param string $instanceId Workflow instance ID
     * @param string $outcome Decision value (must be in step outcomes when configured)
     * @param array<string, mixed> $payload Extra fields (completed_by, note, …)
     */
    public function completeHumanTask(string $instanceId, string $outcome, array $payload = []): WorkflowInstance
    {
        $instance = $this->stateManager->load($instanceId);

        if ($instance->getState() !== WorkflowState::WAITING) {
            throw new InvalidWorkflowStateException(
                "Cannot complete human task for workflow '{$instanceId}' because it is in '{$instance->getState()->value}' state (expected waiting)",
                $instance->getState(),
                WorkflowState::RUNNING,
                $instanceId
            );
        }

        $stepId = $instance->getCurrentStepId();
        if ($stepId === null) {
            throw new \RuntimeException("Workflow '{$instanceId}' is waiting but has no current step");
        }

        $step = $instance->getDefinition()->getStep($stepId);
        if ($step === null) {
            throw new \RuntimeException("Workflow '{$instanceId}' current step '{$stepId}' not found in definition");
        }

        $actionClass = $step->getActionClass();
        if ($actionClass !== HumanTaskAction::class
            && ! is_a((string) $actionClass, HumanTaskAction::class, true)) {
            throw new \RuntimeException("Current step '{$stepId}' is not a human task");
        }

        $config = $step->getConfig();
        $allowed = $config['outcomes'] ?? [];
        if (is_array($allowed) && $allowed !== []) {
            $normalized = [];
            foreach ($allowed as $item) {
                if (is_string($item) && $item !== '') {
                    $normalized[] = $item;
                }
            }
            if ($normalized !== [] && ! in_array($outcome, $normalized, true)) {
                throw new \InvalidArgumentException(
                    "Outcome '{$outcome}' is not allowed for step '{$stepId}'. Allowed: ".implode(', ', $normalized)
                );
            }
        }

        $completedBy = $payload['completed_by'] ?? null;
        $note = $payload['note'] ?? null;
        $completedAt = (new \DateTime)->format('c');

        $humanTasks = $instance->getData()['_human_tasks'] ?? [];
        if (! is_array($humanTasks)) {
            $humanTasks = [];
        }

        $humanTasks[$stepId] = [
            'completed' => true,
            'outcome' => $outcome,
            'completed_by' => $completedBy,
            'note' => $note,
            'completed_at' => $completedAt,
        ];

        $merge = [
            '_human_tasks' => $humanTasks,
            'decision' => $outcome,
            'last_human_outcome' => $outcome,
            'last_human_step' => $stepId,
        ];

        // Track where kemaskini should return after applicant resubmits
        if ($outcome === 'kemaskini') {
            $role = $config['assign_to_role'] ?? null;
            $merge['query_return_step'] = $stepId;
            $merge['query_return_role'] = is_string($role) ? $role : null;
            $merge['query_count'] = (int) ($instance->getData()['query_count'] ?? 0) + 1;
        }

        if ($outcome === 'resubmit') {
            // Keep query_return_step for routing; clear active decision to the resubmit outcome
            $merge['decision'] = 'resubmit';
        }

        $instance->mergeData($merge);
        $this->stateManager->save($instance);

        $this->executor->execute($instance);

        return $this->stateManager->load($instanceId);
    }

    /**
     * Get a workflow instance by its ID.
     *
     * Retrieves the complete workflow instance including its current state,
     * execution history, and context data.
     *
     * @param string $instanceId The workflow instance ID
     * @return WorkflowInstance The workflow instance
     *
     * @throws WorkflowInstanceNotFoundException If the workflow instance doesn't exist
     *
     * @example Getting workflow instance details
     * ```php
     * $instance = $engine->getInstance('workflow-123');
     *
     * echo "Workflow: " . $instance->getDefinition()->getName();
     * echo "State: " . $instance->getState()->label();
     * echo "Progress: " . $instance->getProgress() . "%";
     * ```
     */
    public function getInstance(string $instanceId): WorkflowInstance
    {
        return $this->stateManager->load($instanceId);
    }

    /**
     * Get all workflow instances with optional filtering.
     *
     * Retrieves workflow instances based on the provided filters.
     * Useful for building dashboards, monitoring, and reporting.
     *
     * @param array<string, mixed> $filters Optional filters to apply
     *                                      - 'state': Filter by workflow state (e.g., 'running', 'completed')
     *                                      - 'definition_name': Filter by workflow definition name
     *                                      - 'created_after': Filter by creation date (DateTime or string)
     *                                      - 'created_before': Filter by creation date (DateTime or string)
     *                                      - 'limit': Maximum number of results to return
     *                                      - 'offset': Number of results to skip (for pagination)
     * @return WorkflowInstance[] Array of workflow instances matching the filters
     *
     * @throws \InvalidArgumentException If invalid filters are provided
     *
     * @example Getting recent failed workflows
     * ```php
     * $failedWorkflows = $engine->getInstances([
     *     'state' => 'failed',
     *     'created_after' => (new \DateTime())->modify('-7 days'),
     *     'limit' => 50
     * ]);
     *
     * foreach ($failedWorkflows as $workflow) {
     *     echo "Failed: " . $workflow->getId() . "\n";
     * }
     * ```
     */
    public function getInstances(array $filters = []): array
    {
        // Convert WorkflowState enum to string value for storage layer
        if (isset($filters['state']) && $filters['state'] instanceof WorkflowState) {
            $filters['state'] = $filters['state']->value;
        }

        return $this->storage->findInstances($filters);
    }

    /**
     * Cancel a workflow instance
     */
    public function cancel(string $instanceId, string $reason = ''): WorkflowInstance
    {
        $instance = $this->stateManager->load($instanceId);

        if ($instance->getState()->isFinished()) {
            throw new InvalidWorkflowStateException(
                "Cannot cancel workflow '{$instanceId}' because it is in '{$instance->getState()->value}' state",
                $instance->getState(),
                WorkflowState::CANCELLED,
                $instanceId
            );
        }

        $instance->setState(WorkflowState::CANCELLED);
        $this->stateManager->save($instance);

        // Dispatch cancel event
        $this->dispatchEvent(new WorkflowCancelledEvent(
            $instance,
            $reason
        ));

        return $instance;
    }

    /**
     * Get workflow status
     */
    public function getStatus(string $workflowId): array
    {
        $instance = $this->getInstance($workflowId);

        return [
            'workflow_id' => $instance->getId(),
            'name' => $instance->getDefinition()->getName(),
            'state' => $instance->getState()->value,
            'current_step' => $instance->getCurrentStepId(),
            'progress' => $instance->getProgress(),
            'created_at' => $instance->getCreatedAt(),
            'updated_at' => $instance->getUpdatedAt(),
        ];
    }

    /**
     * Safely dispatch an event if event dispatcher is available
     */
    private function dispatchEvent(object $event): void
    {
        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
