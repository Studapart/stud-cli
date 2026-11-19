<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\WorkItem;

interface ItemStartWorkflow
{
    /**
     * @param array<string, mixed> $projectConfig
     */
    public function transition(WorkflowEntryRecorder $recorder, string $key, array $projectConfig): void;

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function branchPrefix(WorkflowEntryRecorder $recorder, WorkItem $issue, array $projectConfig): string;
}
