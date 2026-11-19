<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\StateChange;
use App\DTO\WorkItem;
use App\Enum\WorkflowChannel;
use App\Service\BranchNameGenerator;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;

final class JiraItemStartWorkflow implements ItemStartWorkflow
{
    public function __construct(
        private readonly ItemTrackerGateway $tracker,
        private readonly PromptInterface $prompt,
        private readonly GitRepository $gitRepository,
    ) {
    }

    public function transition(WorkflowEntryRecorder $recorder, string $key, array $projectConfig): void
    {
        $projectKey = $this->gitRepository->getProjectKeyFromIssueKey($key);
        $transitionId = $this->resolveTransitionId($recorder, $key, $projectKey, $projectConfig);
        if ($transitionId !== null) {
            $this->tracker->applyStateChange($recorder, $key, (string) $transitionId);
        }
    }

    public function branchPrefix(WorkflowEntryRecorder $recorder, WorkItem $issue, array $projectConfig): string
    {
        return BranchNameGenerator::prefixForIssueType($issue->issueType);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function resolveTransitionId(WorkflowEntryRecorder $recorder, string $key, string $projectKey, array $projectConfig): ?int
    {
        if (isset($projectConfig['projectKey']) && $projectConfig['projectKey'] === $projectKey && isset($projectConfig['transitionId'])) {
            $id = (int) $projectConfig['transitionId'];
            $recorder->addLine(
                WorkflowEntryRecorder::VERBOSITY_VERBOSE,
                MessageRef::key('item.start.using_cached_transition', ['id' => $id]),
                WorkflowChannel::Jira,
            );

            return $id;
        }

        return $this->promptForTransitionId($recorder, $key, $projectKey);
    }

    private function promptForTransitionId(WorkflowEntryRecorder $recorder, string $key, string $projectKey): ?int
    {
        try {
            return $this->chooseTransition($recorder, $key, $projectKey);
        } catch (\Exception $e) {
            $this->tracker->warnFailure($recorder, 'item.start.transition_error', $e);

            return null;
        }
    }

    /**
     * @throws \Exception
     */
    private function chooseTransition(WorkflowEntryRecorder $recorder, string $key, string $projectKey): ?int
    {
        $transitions = $this->tracker->listStateChanges($key);
        if ($transitions === []) {
            $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.no_transitions', ['key' => $key]));

            return null;
        }

        $options = array_map(fn (StateChange $t) => "{$t->name} (ID: {$t->id})", $transitions);
        $transitionId = $this->transitionIdFromChoice((string) $this->prompt->choice(MessageRef::key('item.start.select_transition'), $options));
        if ($this->prompt->confirm(MessageRef::key('item.start.save_transition', ['project' => $projectKey]), true)) {
            $this->gitRepository->writeProjectConfig(['projectKey' => $projectKey, 'transitionId' => $transitionId]);
            $recorder->addLine(
                WorkflowEntryRecorder::VERBOSITY_VERBOSE,
                MessageRef::key('item.start.transition_saved', ['project' => $projectKey]),
                WorkflowChannel::Jira,
            );
        }

        return $transitionId;
    }

    private function transitionIdFromChoice(string $selected): int
    {
        preg_match('/ID: (\d+)\)$/', $selected, $matches);
        if (! isset($matches[1])) {
            throw new \RuntimeException('Unable to extract transition ID from selection');
        }

        return (int) $matches[1];
    }
}
