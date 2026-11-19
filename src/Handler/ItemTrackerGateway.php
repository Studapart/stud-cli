<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\StateChange;
use App\DTO\WorkItem;
use App\Enum\WorkflowChannel;
use App\Exception\ApiException;
use App\Service\IssueTrackerPort;

final class ItemTrackerGateway
{
    public function __construct(private readonly IssueTrackerPort $provider)
    {
    }

    public function fetchIssue(string $key): WorkItem
    {
        return $this->provider->getIssue($key);
    }

    public function assign(WorkflowEntryRecorder $recorder, string $key, string $lineKey, string $warningKey): void
    {
        try {
            $recorder->addLine(
                WorkflowEntryRecorder::VERBOSITY_VERBOSE,
                MessageRef::key($lineKey, ['key' => $key]),
                WorkflowChannel::Jira,
            );
            $this->provider->assign($key);
        } catch (\Exception $e) {
            $this->warnFailure($recorder, $warningKey, $e);
        }
    }

    /**
     * @return list<StateChange>
     */
    public function listStateChanges(string $key): array
    {
        return $this->provider->listItemStateChanges($key);
    }

    public function applyStateChange(WorkflowEntryRecorder $recorder, string $key, string $stateChangeId): void
    {
        try {
            $this->provider->applyStateChange($key, $stateChangeId);
            $recorder->addSuccess(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('item.start.transition_success', ['key' => $key]),
            );
        } catch (\Exception $e) {
            $this->warnFailure($recorder, 'item.start.transition_exec_error', $e);
        }
    }

    public function warnFailure(WorkflowEntryRecorder $recorder, string $messageKey, \Exception $e): void
    {
        $recorder->addWarning(
            WorkflowEntryRecorder::VERBOSITY_NORMAL,
            MessageRef::key($messageKey, ['error' => $e->getMessage()]),
        );
        if ($e instanceof ApiException) {
            $recorder->addText(
                WorkflowEntryRecorder::VERBOSITY_VERBOSE,
                ['', ' Technical details: ' . $e->getTechnicalDetails()],
            );
        }
    }
}
