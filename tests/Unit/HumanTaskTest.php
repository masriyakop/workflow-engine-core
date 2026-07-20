<?php

use SolutionForest\WorkflowEngine\Core\WorkflowEngine;
use SolutionForest\WorkflowEngine\Core\WorkflowState;
use SolutionForest\WorkflowEngine\Exceptions\InvalidWorkflowStateException;
use SolutionForest\WorkflowEngine\Tests\Support\InMemoryStorage;

beforeEach(function () {
    $this->storage = new InMemoryStorage;
    $this->engine = new WorkflowEngine($this->storage);
});

test('human task pauses workflow in waiting state', function () {
    $definition = [
        'name' => 'Approval Flow',
        'steps' => [
            [
                'id' => 'review',
                'action' => 'human',
                'parameters' => [
                    'assign_to_role' => 'sos',
                    'outcomes' => ['lengkap', 'ditolak'],
                    'sla_hours' => 4,
                ],
            ],
            [
                'id' => 'done',
                'action' => 'log',
                'parameters' => ['message' => 'Finished'],
            ],
        ],
    ];

    $id = $this->engine->start('human-1', $definition, [
        'ref_no' => 'RG-KE-001',
        'applicant_name' => 'Ali',
    ]);

    $instance = $this->engine->getInstance($id);
    expect($instance->getState())->toBe(WorkflowState::WAITING);
    expect($instance->getCurrentStepId())->toBe('review');
    expect($instance->isStepCompleted('review'))->toBeFalse();
});

test('completeHumanTask advances to next step and may wait again', function () {
    $definition = [
        'name' => 'Two Human Gates',
        'steps' => [
            [
                'id' => 'sos',
                'action' => 'human',
                'parameters' => [
                    'assign_to_role' => 'sos',
                    'outcomes' => ['lengkap', 'kemaskini', 'ditolak'],
                ],
            ],
            [
                'id' => 'technical',
                'action' => 'human',
                'parameters' => [
                    'assign_to_role' => 'technical',
                    'outcomes' => ['lengkap', 'ditolak'],
                ],
            ],
        ],
    ];

    $id = $this->engine->start('human-2', $definition);

    expect($this->engine->getInstance($id)->getState())->toBe(WorkflowState::WAITING);
    expect($this->engine->getInstance($id)->getCurrentStepId())->toBe('sos');

    $this->engine->completeHumanTask($id, 'lengkap', ['completed_by' => 'user-1']);

    $afterSos = $this->engine->getInstance($id);
    expect($afterSos->getState())->toBe(WorkflowState::WAITING);
    expect($afterSos->getCurrentStepId())->toBe('technical');
    expect($afterSos->isStepCompleted('sos'))->toBeTrue();
    expect($afterSos->getData()['decision'])->toBe('lengkap');

    $this->engine->completeHumanTask($id, 'lengkap', ['completed_by' => 'user-2']);

    $done = $this->engine->getInstance($id);
    expect($done->getState())->toBe(WorkflowState::COMPLETED);
    expect($done->isStepCompleted('technical'))->toBeTrue();
});

test('completeHumanTask rejects invalid outcome', function () {
    $definition = [
        'name' => 'Strict Outcomes',
        'steps' => [
            [
                'id' => 'review',
                'action' => 'approval',
                'parameters' => [
                    'assign_to_role' => 'approver',
                    'outcomes' => ['lulus', 'ditolak'],
                ],
            ],
        ],
    ];

    $id = $this->engine->start('human-3', $definition);

    expect(fn () => $this->engine->completeHumanTask($id, 'maybe'))
        ->toThrow(InvalidArgumentException::class);
});

test('completeHumanTask rejects when not waiting', function () {
    $definition = [
        'name' => 'Log Only',
        'steps' => [
            [
                'id' => 'log-1',
                'action' => 'log',
                'parameters' => ['message' => 'hi'],
            ],
        ],
    ];

    $id = $this->engine->start('human-4', $definition);

    expect(fn () => $this->engine->completeHumanTask($id, 'lengkap'))
        ->toThrow(InvalidWorkflowStateException::class);
});
