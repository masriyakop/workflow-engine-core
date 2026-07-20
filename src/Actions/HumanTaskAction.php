<?php

namespace SolutionForest\WorkflowEngine\Actions;

use SolutionForest\WorkflowEngine\Core\ActionResult;
use SolutionForest\WorkflowEngine\Core\WorkflowContext;

/**
 * Pause workflow execution until a human completes an assigned task.
 *
 * Config:
 * - assign_to_role (required): role key that owns the task (e.g. sos, technical, approver)
 * - outcomes (optional): list of allowed decision values
 * - sla_hours (optional): customer-charter SLA for the task
 * - tab (optional): inbox tab hint, default "new"
 */
class HumanTaskAction extends BaseAction
{
    public function getName(): string
    {
        return 'Human Task';
    }

    public function getDescription(): string
    {
        return 'Wait for a human decision / approval before continuing';
    }

    protected function doExecute(WorkflowContext $context): ActionResult
    {
        $role = $this->getConfig('assign_to_role');
        if (! is_string($role) || trim($role) === '') {
            return ActionResult::failure('HumanTaskAction requires assign_to_role');
        }

        $stepId = $context->getStepId();
        $data = $context->getData();
        $humanTasks = is_array($data['_human_tasks'] ?? null) ? $data['_human_tasks'] : [];
        $completion = is_array($humanTasks[$stepId] ?? null) ? $humanTasks[$stepId] : null;

        if (($completion['completed'] ?? false) === true) {
            return ActionResult::success([
                'human_task_completed' => true,
                'step_id' => $stepId,
                'outcome' => $completion['outcome'] ?? null,
                'decision' => $completion['outcome'] ?? null,
                'completed_by' => $completion['completed_by'] ?? null,
                'note' => $completion['note'] ?? null,
            ]);
        }

        $outcomes = $this->normalizeOutcomes($this->getConfig('outcomes', []));
        $tab = $this->getConfig('tab', 'new');
        $slaHours = $this->getConfig('sla_hours');

        return ActionResult::waiting([
            'human_task' => true,
            'step_id' => $stepId,
            'assign_to_role' => $role,
            'outcomes' => $outcomes,
            'tab' => is_string($tab) && $tab !== '' ? $tab : 'new',
            'sla_hours' => is_numeric($slaHours) ? (float) $slaHours : null,
        ], [
            'action' => 'human_task',
        ]);
    }

    /**
     * @param mixed $outcomes
     * @return list<string>
     */
    private function normalizeOutcomes(mixed $outcomes): array
    {
        if (! is_array($outcomes)) {
            return [];
        }

        $normalized = [];
        foreach ($outcomes as $outcome) {
            if (is_string($outcome) && trim($outcome) !== '') {
                $normalized[] = trim($outcome);
            }
        }

        return array_values(array_unique($normalized));
    }
}
