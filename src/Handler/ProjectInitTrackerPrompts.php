<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\ProjectStudConfigKeys;
use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Service\Prompt\PromptInterface;

/**
 * Asks the tracker-specific project fields (Linear team, Jira defaults, workflow metadata) for the selected trackers.
 */
class ProjectInitTrackerPrompts
{
    public function __construct(
        private readonly PromptInterface $prompt,
        private readonly ProjectInitPromptContext $context,
    ) {
    }

    /**
     * @param array<string, mixed> $existing     Stored project config
     * @param array<string, mixed> $mergedConfig Stored project config with the answers given so far
     * @return array<string, mixed> input-keyed patches
     */
    public function patches(
        ProjectInitTrackers $trackers,
        array $existing,
        array $mergedConfig,
        WorkflowEntryRecorder $recorder,
    ): array {
        $patches = [];
        if ($trackers->linearTeamKey) {
            $patches = $this->promptLinearTeamKey($mergedConfig);
            if (isset($patches['linearTeamKey'])) {
                $mergedConfig[ProjectStudConfigKeys::LINEAR_TEAM_KEY] = $patches['linearTeamKey'];
            }
        }
        if ($trackers->jira) {
            $patches = array_merge($patches, $this->promptJiraDefaultProject($existing));
            $patches = array_merge($patches, $this->promptConfluenceDefaultSpace($existing));
            $patches = array_merge($patches, $this->promptTransitionFromWorkflow($mergedConfig, $recorder));
        }
        if ($trackers->linear) {
            $patches = array_merge($patches, $this->promptLinearFields($mergedConfig, $recorder));
        }

        return $patches;
    }

    /**
     * @param array<string, mixed> $mergedConfig
     * @return array<string, mixed>
     */
    protected function promptLinearTeamKey(array $mergedConfig): array
    {
        $jiraKey = $this->resolveProjectKey($mergedConfig);
        if ($jiraKey === null) {
            return [];
        }

        $existingLinear = isset($mergedConfig['linearTeamKey']) && is_string($mergedConfig['linearTeamKey'])
            ? strtoupper(trim($mergedConfig['linearTeamKey']))
            : '';
        $default = $existingLinear !== '' ? $existingLinear : $jiraKey;
        $answer = $this->prompt->ask(
            MessageRef::key('config.project_init.prompt_linear_team_key'),
            $default,
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        $value = strtoupper(trim((string) $answer));
        if ($value === $jiraKey && $existingLinear === '') {
            return [];
        }
        if ($value === $existingLinear) {
            return [];
        }

        return ['linearTeamKey' => $value];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptJiraDefaultProject(array $existing): array
    {
        $current = isset($existing['JIRA_DEFAULT_PROJECT']) ? (string) $existing['JIRA_DEFAULT_PROJECT'] : '';
        $answer = $this->prompt->ask(
            MessageRef::key('config.project_init.prompt_jira_default_project'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['jiraDefaultProject' => strtoupper(trim((string) $answer))];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptConfluenceDefaultSpace(array $existing): array
    {
        $current = isset($existing['CONFLUENCE_DEFAULT_SPACE']) ? (string) $existing['CONFLUENCE_DEFAULT_SPACE'] : '';
        $answer = $this->prompt->ask(
            MessageRef::key('config.project_init.prompt_confluence_default_space'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['confluenceDefaultSpace' => trim((string) $answer)];
    }

    /**
     * @param array<string, mixed> $mergedConfig
     * @return array<string, mixed>
     */
    protected function promptTransitionFromWorkflow(array $mergedConfig, WorkflowEntryRecorder $recorder): array
    {
        $projectKey = $this->resolveProjectKey($mergedConfig);
        if ($projectKey === null) {
            return [];
        }

        $transitionId = $this->context->metadata->chooseJiraTransitionId($recorder, $projectKey, $mergedConfig);
        if ($transitionId === null) {
            return [];
        }

        return ['transitionId' => $transitionId];
    }

    /**
     * @param array<string, mixed> $mergedConfig
     * @return array<string, mixed>
     */
    protected function promptLinearFields(array $mergedConfig, WorkflowEntryRecorder $recorder): array
    {
        $scopeKey = $this->context->scope->resolveLinearTeamKey($mergedConfig);
        if ($scopeKey === null) {
            return [];
        }

        $patches = [];
        $startStateId = $this->context->metadata->chooseLinearStartStateId($recorder, $scopeKey, $mergedConfig);
        if ($startStateId !== null) {
            $patches['linearStartStateId'] = $startStateId;
        }

        $labelGroupId = $this->context->metadata->chooseLinearTypeLabelGroupId($recorder, $scopeKey, $mergedConfig);
        if ($labelGroupId !== null) {
            $patches['linearTypeLabelGroupId'] = $labelGroupId;
            /** @var array<string, string>|null $branchPrefixes */
            $branchPrefixes = $this->context->metadata->buildLinearBranchPrefixMap(
                $recorder,
                $scopeKey,
                $mergedConfig,
                $labelGroupId,
            );
            if ($branchPrefixes !== null) {
                $patches['linearTypeBranchPrefixes'] = $branchPrefixes;
            }
        }

        return $patches;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function resolveProjectKey(array $config): ?string
    {
        if (! isset($config['projectKey']) || ! is_string($config['projectKey'])) {
            return null;
        }
        $key = strtoupper(trim($config['projectKey']));

        return $key !== '' ? $key : null;
    }
}
