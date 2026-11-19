<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\ProjectStudConfigKeys;
use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\StateChange;
use App\DTO\WorkItem;
use App\Enum\WorkflowChannel;
use App\Service\GitRepository;
use App\Service\LinearTypeLabelResolver;
use App\Service\Prompt\NonInteractivePromptService;
use App\Service\Prompt\PromptInterface;

final class LinearItemStartWorkflow implements ItemStartWorkflow
{
    public function __construct(
        private readonly ItemTrackerGateway $tracker,
        private readonly PromptInterface $prompt,
        private readonly GitRepository $gitRepository,
        private readonly LinearTypeLabelResolver $linearTypeLabelResolver,
    ) {
    }

    public function transition(WorkflowEntryRecorder $recorder, string $key, array $projectConfig): void
    {
        $stateId = $this->resolveStartStateId($recorder, $key, $projectConfig);
        if ($stateId !== null) {
            $this->tracker->applyStateChange($recorder, $key, $stateId);
        }
    }

    public function branchPrefix(WorkflowEntryRecorder $recorder, WorkItem $issue, array $projectConfig): string
    {
        $teamKey = isset($projectConfig[ProjectStudConfigKeys::PROJECT_KEY]) && is_string($projectConfig[ProjectStudConfigKeys::PROJECT_KEY])
            ? $projectConfig[ProjectStudConfigKeys::PROJECT_KEY]
            : $this->gitRepository->getProjectKeyFromIssueKey($issue->key);

        $result = $this->linearTypeLabelResolver->resolveBranchPrefix(array_values($issue->labels), $projectConfig, $teamKey);
        if ($result['warning'] !== null) {
            $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, $result['warning']);
        }
        if ($result['matchedLabel'] !== null) {
            $recorder->addLine(
                WorkflowEntryRecorder::VERBOSITY_VERBOSE,
                MessageRef::key('item.start.linear_type_label_matched', [
                    'label' => $result['matchedLabel'],
                    'prefix' => $result['prefix'],
                ]),
            );
        }

        return $result['prefix'];
    }

    public function promptForStartStateId(WorkflowEntryRecorder $recorder, string $key): ?string
    {
        try {
            return $this->chooseStartState($recorder, $key);
        } catch (\Exception $e) {
            $this->tracker->warnFailure($recorder, 'item.start.transition_error', $e);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function resolveStartStateId(WorkflowEntryRecorder $recorder, string $key, array $projectConfig): ?string
    {
        $cached = $projectConfig[ProjectStudConfigKeys::LINEAR_START_STATE_ID] ?? null;
        if (is_string($cached) && trim($cached) !== '') {
            $stateId = trim($cached);
            $recorder->addLine(
                WorkflowEntryRecorder::VERBOSITY_VERBOSE,
                MessageRef::key('item.start.using_cached_linear_state', ['id' => $stateId]),
                WorkflowChannel::Jira,
            );

            return $stateId;
        }

        if ($this->prompt instanceof NonInteractivePromptService) {
            $recorder->addWarning(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('item.start.no_cached_linear_state_agent', ['key' => $key]),
            );

            return null;
        }

        return $this->promptForStartStateId($recorder, $key);
    }

    /**
     * @throws \Exception
     */
    private function chooseStartState(WorkflowEntryRecorder $recorder, string $key): ?string
    {
        $states = $this->tracker->listStateChanges($key);
        if ($states === []) {
            $recorder->addWarning(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('item.start.no_linear_states', ['key' => $key]),
            );

            return null;
        }

        $options = array_map(fn (StateChange $state) => "{$state->name} (ID: {$state->id})", $states);
        $stateId = $this->stateIdFromChoice((string) $this->prompt->choice(MessageRef::key('item.start.select_linear_state'), $options));
        if ($this->prompt->confirm(MessageRef::key('item.start.save_linear_state'), true)) {
            $this->gitRepository->writeProjectConfig([
                ProjectStudConfigKeys::LINEAR_START_STATE_ID => $stateId,
            ]);
            $recorder->addLine(
                WorkflowEntryRecorder::VERBOSITY_VERBOSE,
                MessageRef::key('item.start.linear_state_saved'),
                WorkflowChannel::Jira,
            );
        }

        return $stateId;
    }

    private function stateIdFromChoice(string $selected): string
    {
        preg_match('/ID: ([^)]+)\)$/', $selected, $matches);
        if (! isset($matches[1]) || trim($matches[1]) === '') {
            throw new \RuntimeException('Unable to extract Linear state ID from selection');
        }

        return trim($matches[1]);
    }
}
