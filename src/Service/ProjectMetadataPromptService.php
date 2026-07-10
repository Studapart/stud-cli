<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Enum\IssueTrackerProvider;
use App\Exception\ApiException;
use App\Response\ProjectsLabelsResponse;
use App\Response\ProjectsWorkflowResponse;
use App\Service\Prompt\PromptInterface;

/**
 * Guided pickers for project-init using projects:workflow and projects:labels data.
 */
class ProjectMetadataPromptService
{
    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        private readonly ProjectsWorkflowNormalizer $normalizer,
        /** @var array<string, mixed> */
        private readonly array $globalConfig,
        private readonly PromptInterface $prompt,
        private readonly ProjectScopeKeyResolver $scopeKeyResolver = new ProjectScopeKeyResolver(),
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function chooseJiraTransitionId(
        WorkflowEntryRecorder $recorder,
        string $projectKey,
        array $projectConfig,
    ): ?int {
        $scopeKey = $this->scopeKeyResolver->resolveJiraProjectKey($projectConfig) ?? $projectKey;
        $response = $this->fetchWorkflow(IssueTrackerProvider::Jira->value, $scopeKey);
        if (! $response->isSuccess()) {
            $this->logWorkflowFailure($recorder, $response);

            return null;
        }

        $transitions = array_values(array_filter(
            $response->stateChanges,
            static fn (array $row): bool => ($row['provider'] ?? '') === IssueTrackerProvider::Jira->value,
        ));
        if ($transitions === []) {
            $this->logWorkflowEmpty($recorder, $scopeKey, $response);

            return null;
        }

        $current = isset($projectConfig['transitionId']) ? (string) (int) $projectConfig['transitionId'] : null;
        $selectedId = $this->chooseWorkflowItemId(
            MessageRef::key('config.project_init.prompt_select_transition'),
            $this->toPickerItems($transitions),
            $current,
        );
        if ($selectedId === null || ! ctype_digit($selectedId)) {
            return null;
        }

        return (int) $selectedId;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function chooseLinearStartStateId(
        WorkflowEntryRecorder $recorder,
        string $projectKey,
        array $projectConfig,
    ): ?string {
        $scopeKey = $this->scopeKeyResolver->resolveLinearTeamKey($projectConfig) ?? $projectKey;
        $response = $this->fetchWorkflow(IssueTrackerProvider::Linear->value, $scopeKey);
        if (! $response->isSuccess()) {
            $this->logWorkflowFailure($recorder, $response);

            return null;
        }

        $states = array_values(array_filter(
            $response->stateChanges,
            static fn (array $row): bool => ($row['provider'] ?? '') === IssueTrackerProvider::Linear->value,
        ));
        if ($states === []) {
            $this->logWorkflowEmpty($recorder, $scopeKey, $response);

            return null;
        }

        $current = isset($projectConfig['linearStartStateId']) && is_string($projectConfig['linearStartStateId'])
            ? $projectConfig['linearStartStateId']
            : null;

        return $this->chooseWorkflowItemId(
            MessageRef::key('config.project_init.prompt_select_linear_start_state'),
            $this->toPickerItems($states),
            $current,
        );
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function chooseLinearTypeLabelGroupId(
        WorkflowEntryRecorder $recorder,
        string $projectKey,
        array $projectConfig,
    ): ?string {
        $scopeKey = $this->scopeKeyResolver->resolveLinearTeamKey($projectConfig) ?? $projectKey;
        $response = $this->fetchLabelGroups(IssueTrackerProvider::Linear->value, $scopeKey);
        if (! $response->isSuccess()) {
            $this->logLabelsFailure($recorder, $response);

            return null;
        }

        if ($response->groups === []) {
            return null;
        }

        $groups = array_map(
            static fn (array $group): array => [
                'id' => (string) $group['id'],
                'name' => (string) $group['name'],
            ],
            $response->groups,
        );

        $current = isset($projectConfig['linearTypeLabelGroupId']) && is_string($projectConfig['linearTypeLabelGroupId'])
            ? $projectConfig['linearTypeLabelGroupId']
            : null;

        return $this->chooseWorkflowItemId(
            MessageRef::key('config.project_init.prompt_select_linear_label_group'),
            $groups,
            $current,
        );
    }

    /**
     * @param array<string, mixed> $projectConfig
     * @return array<string, string>|null
     */
    public function buildLinearBranchPrefixMap(
        WorkflowEntryRecorder $recorder,
        string $projectKey,
        array $projectConfig,
        string $labelGroupId,
    ): ?array {
        $scopeKey = $this->scopeKeyResolver->resolveLinearTeamKey($projectConfig) ?? $projectKey;
        $response = $this->fetchLabelGroups(IssueTrackerProvider::Linear->value, $scopeKey);
        if (! $response->isSuccess()) {
            $this->logLabelsFailure($recorder, $response);

            return null;
        }

        $labels = $this->findGroupChildLabels($response->groups, $labelGroupId);
        if ($labels === []) {
            $recorder->addWarning(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('config.project_init.no_labels_in_group'),
            );

            return null;
        }

        $map = $this->promptBranchPrefixMap($recorder, $labels);
        if ($map === []) {
            return null;
        }

        $this->logBranchPrefixSummary($recorder, $map);
        if (! $this->prompt->confirm(MessageRef::key('config.project_init.confirm_linear_branch_prefix_map'), true)) {
            return null;
        }

        return $map;
    }

    /**
     * @param list<array{id: string, name: string}> $items
     */
    protected function chooseWorkflowItemId(
        MessageRef $question,
        array $items,
        ?string $currentId,
    ): ?string {
        $skipLabel = $this->skipChoiceLabel();
        $options = [$skipLabel];
        $default = $skipLabel;

        foreach ($items as $item) {
            $option = "{$item['name']} (ID: {$item['id']})";
            $options[] = $option;
            if ($currentId !== null && $currentId === $item['id']) {
                $default = $option;
            }
        }

        $selected = (string) $this->prompt->choice($question, $options, $default);
        if ($selected === $skipLabel) {
            return null;
        }

        return $this->extractIdFromChoice($selected);
    }

    protected function extractIdFromChoice(string $selected): ?string
    {
        if (preg_match('/ID: ([^)]+)\)$/', $selected, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param list<array{id: string, name: string}> $labels
     * @return array<string, string>
     */
    protected function promptBranchPrefixMap(WorkflowEntryRecorder $recorder, array $labels): array
    {
        unset($recorder);
        $map = [];
        foreach ($labels as $label) {
            $name = $label['name'];
            $default = self::defaultPrefixForLabelName($name);
            $answer = $this->prompt->ask(
                MessageRef::key('config.project_init.prompt_linear_branch_prefix', ['label' => $name]),
                $default,
            );
            if ($answer === null || trim($answer) === '') {
                continue;
            }
            $map[$name] = trim($answer);
        }

        return $map;
    }

    /**
     * @param list<array{id: string, name: string, labels: list<array{id: string, name: string}>}> $groups
     * @return list<array{id: string, name: string}>
     */
    protected function findGroupChildLabels(array $groups, string $labelGroupId): array
    {
        foreach ($groups as $group) {
            if ((string) $group['id'] !== $labelGroupId) {
                continue;
            }

            return array_map(
                static fn (array $label): array => [
                    'id' => (string) $label['id'],
                    'name' => (string) $label['name'],
                ],
                $group['labels'],
            );
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{id: string, name: string}>
     */
    protected function toPickerItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => [
                'id' => (string) $item['id'],
                'name' => (string) $item['name'],
            ],
            $items,
        );
    }

    /**
     * @param array<string, string> $map
     */
    protected function logBranchPrefixSummary(WorkflowEntryRecorder $recorder, array $map): void
    {
        foreach ($map as $label => $prefix) {
            $recorder->addText(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('config.project_init.linear_branch_prefix_summary', [
                    'label' => $label,
                    'prefix' => $prefix,
                ]),
            );
        }
    }

    protected function logWorkflowFailure(WorkflowEntryRecorder $recorder, ProjectsWorkflowResponse $response): void
    {
        $recorder->addWarning(
            WorkflowEntryRecorder::VERBOSITY_NORMAL,
            MessageRef::key('config.project_init.workflow_fetch_failed', [
                'error' => (string) ($response->error ?? 'unknown'),
            ]),
        );
    }

    protected function logWorkflowEmpty(
        WorkflowEntryRecorder $recorder,
        string $projectKey,
        ProjectsWorkflowResponse $response,
    ): void {
        unset($response);
        $recorder->addWarning(
            WorkflowEntryRecorder::VERBOSITY_NORMAL,
            MessageRef::key('project.workflow.no_state_changes', ['project' => $projectKey]),
        );
    }

    protected function logLabelsFailure(WorkflowEntryRecorder $recorder, ProjectsLabelsResponse $response): void
    {
        $recorder->addWarning(
            WorkflowEntryRecorder::VERBOSITY_NORMAL,
            MessageRef::key('config.project_init.labels_fetch_failed', [
                'error' => (string) ($response->error ?? 'unknown'),
            ]),
        );
    }

    protected function fetchWorkflow(string $provider, string $scopeKey): ProjectsWorkflowResponse
    {
        $resolution = $this->portSupplier->resolveForProvider($provider, $this->globalConfig);
        if (! $resolution['ok']) {
            return ProjectsWorkflowResponse::error($resolution['error']);
        }

        try {
            $stateChanges = $resolution['port']->listProjectStateChanges($scopeKey);
        } catch (ApiException $e) {
            return ProjectsWorkflowResponse::error(
                MessageRef::key('project.workflow.error_fetch', ['error' => $e->getMessage()])
            );
        } catch (\Throwable $e) {
            return ProjectsWorkflowResponse::error(
                MessageRef::key('project.workflow.error_fetch', ['error' => $e->getMessage()])
            );
        }

        $mapped = $this->normalizer->fromStateChanges($stateChanges, $resolution['provider']);
        if ($mapped === []) {
            return ProjectsWorkflowResponse::success([], [
                ResponseMessage::warning(MessageRef::key('project.workflow.no_state_changes', ['project' => $scopeKey])),
            ]);
        }

        return ProjectsWorkflowResponse::success($mapped);
    }

    protected function fetchLabelGroups(string $provider, string $scopeKey): ProjectsLabelsResponse
    {
        $resolution = $this->portSupplier->resolveForProvider($provider, $this->globalConfig);
        if (! $resolution['ok']) {
            return ProjectsLabelsResponse::error($resolution['error']);
        }

        if (! $resolution['port'] instanceof IssueTrackerLabelGroupsCapable) {
            return ProjectsLabelsResponse::success([]);
        }

        try {
            $groups = $resolution['port']->listLabelGroups($scopeKey, true);
        } catch (ApiException $e) {
            return ProjectsLabelsResponse::error(
                MessageRef::key('project.labels.error_fetch', ['error' => $e->getMessage()])
            );
        } catch (\Throwable $e) {
            return ProjectsLabelsResponse::error(
                MessageRef::key('project.labels.error_fetch', ['error' => $e->getMessage()])
            );
        }

        return ProjectsLabelsResponse::success($groups);
    }

    public static function defaultPrefixForLabelName(string $labelName): string
    {
        return BranchNameGenerator::prefixForIssueType($labelName);
    }

    protected function skipChoiceLabel(): string
    {
        return $this->messageRenderer?->render(MessageRef::key('config.project_init.picker_skip'))
            ?? 'Skip (leave unset)';
    }
}
