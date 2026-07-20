<?php

namespace SolutionForest\WorkflowEngine\Events;

use SolutionForest\WorkflowEngine\Core\Step;
use SolutionForest\WorkflowEngine\Core\WorkflowInstance;

/**
 * Fired when a workflow enters WAITING (e.g. human / approval task).
 */
final readonly class WorkflowWaitingEvent
{
    public function __construct(
        public WorkflowInstance $instance,
        public Step $step,
    ) {}

    public function getWorkflowId(): string
    {
        return $this->instance->getId();
    }

    public function getStepId(): string
    {
        return $this->step->getId();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStepConfig(): array
    {
        return $this->step->getConfig();
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->instance->getData();
    }
}
