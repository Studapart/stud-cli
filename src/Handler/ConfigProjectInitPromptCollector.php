<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\ProjectStudConfigFieldMap;
use App\Config\ProjectStudConfigKeys;
use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;

/**
 * Interactive prompts for stud config:project-init (keeps ConfigProjectInitHandler within size limits).
 */
class ConfigProjectInitPromptCollector
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ProjectGitPrompts $gitPrompts,
        private readonly PromptInterface $prompt,
        private readonly ProjectInitPromptContext $context,
    ) {
    }

    /**
     * @return array<string, mixed> input-keyed patches
     */
    public function collect(WorkflowEntryRecorder $recorder): array
    {
        $existing = $this->gitRepository->readProjectConfig();
        $globalIssueTrackerProviders = $this->readGlobalIssueTrackerProviders();
        $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.project_init.interactive_title'));
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.project_init.interactive_hint'));

        $patches = [];
        if ($this->context->global->providers->collectsJira($globalIssueTrackerProviders)
            && $this->context->global->providers->collectsLinear($globalIssueTrackerProviders)) {
            $patches = array_merge($patches, $this->promptIssueTrackerProvider($existing));
        }

        $trackers = $this->resolveTrackers($this->mergeProjectConfig($existing, $patches), $globalIssueTrackerProviders);

        $patches = array_merge($patches, $this->promptProjectKey($existing));
        $trackerPrompts = new ProjectInitTrackerPrompts($this->prompt, $this->context);
        $patches = array_merge(
            $patches,
            $trackerPrompts->patches($trackers, $existing, $this->mergeProjectConfig($existing, $patches), $recorder),
        );
        $patches = array_merge($patches, $this->gitPrompts->baseBranchPatches($existing, $recorder));
        $patches = array_merge($patches, $this->promptLabelDefaults($existing));
        $patches = array_merge($patches, $this->gitPrompts->hostingPatches($existing, $recorder));
        $credentialPrompts = new ProjectIssueTrackerCredentialPrompts($this->prompt);
        if ($trackers->jira) {
            $patches = array_merge($patches, $credentialPrompts->jiraPatches($existing));
        }
        if ($trackers->linear) {
            $patches = array_merge($patches, $credentialPrompts->linearPatches());
        }

        return $patches;
    }

    /**
     * @param array<string, mixed> $mergedConfig
     * @param list<string> $globalIssueTrackerProviders
     */
    protected function resolveTrackers(array $mergedConfig, array $globalIssueTrackerProviders): ProjectInitTrackers
    {
        $effectiveProvider = $this->resolveEffectiveIssueTrackerProvider($mergedConfig, $globalIssueTrackerProviders);

        return new ProjectInitTrackers(
            jira: $this->shouldRunJiraPrompts($effectiveProvider),
            linear: $this->shouldRunLinearPrompts($effectiveProvider, $globalIssueTrackerProviders),
            linearTeamKey: $this->shouldRunLinearTeamKeyPrompt($effectiveProvider, $globalIssueTrackerProviders),
        );
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptIssueTrackerProvider(array $existing): array
    {
        $current = ProjectStudConfigKeys::readIssueTrackerProvider($existing) ?? IssueTrackerProvider::Auto->value;
        if (! IssueTrackerProvider::isProjectConfigValue($current)) {
            $current = IssueTrackerProvider::Auto->value;
        }
        $choice = $this->prompt->choice(
            MessageRef::key('config.project_init.prompt_issue_tracker_provider'),
            IssueTrackerProvider::projectConfigValues(),
            $current,
        );

        return ['issueTrackerProvider' => (string) $choice];
    }

    /**
     * @return list<string>
     */
    protected function readGlobalIssueTrackerProviders(): array
    {
        return $this->context->global->readIssueTrackerProviders();
    }

    /**
     * @param array<string, mixed> $existing
     * @param list<string>         $globalIssueTrackerProviders
     */
    protected function resolveEffectiveIssueTrackerProvider(array $existing, array $globalIssueTrackerProviders): string
    {
        $hasJira = $this->context->global->providers->collectsJira($globalIssueTrackerProviders);
        $hasLinear = $this->context->global->providers->collectsLinear($globalIssueTrackerProviders);

        if ($hasJira && ! $hasLinear) {
            return IssueTrackerProvider::Jira->value;
        }
        if ($hasLinear && ! $hasJira) {
            return IssueTrackerProvider::Linear->value;
        }

        $stored = ProjectStudConfigKeys::readIssueTrackerProvider($existing) ?? IssueTrackerProvider::Auto->value;

        return IssueTrackerProvider::isProjectConfigValue($stored) ? $stored : IssueTrackerProvider::Auto->value;
    }

    protected function shouldRunJiraPrompts(string $effectiveProvider): bool
    {
        return $effectiveProvider === IssueTrackerProvider::Jira->value || $effectiveProvider === IssueTrackerProvider::Auto->value;
    }

    /**
     * @param list<string> $globalIssueTrackerProviders
     */
    protected function shouldRunLinearPrompts(string $effectiveProvider, array $globalIssueTrackerProviders): bool
    {
        if ($effectiveProvider === IssueTrackerProvider::Linear->value) {
            return true;
        }

        return $effectiveProvider === IssueTrackerProvider::Auto->value
            && $this->context->global->providers->collectsJira($globalIssueTrackerProviders)
            && $this->context->global->providers->collectsLinear($globalIssueTrackerProviders);
    }

    /**
     * @param list<string> $globalIssueTrackerProviders
     */
    protected function shouldRunLinearTeamKeyPrompt(string $effectiveProvider, array $globalIssueTrackerProviders): bool
    {
        if (! $this->context->global->providers->collectsJira($globalIssueTrackerProviders)
            || ! $this->context->global->providers->collectsLinear($globalIssueTrackerProviders)) {
            return false;
        }

        return $effectiveProvider === IssueTrackerProvider::Linear->value
            || $effectiveProvider === IssueTrackerProvider::Auto->value;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $patches
     * @return array<string, mixed>
     */
    protected function mergeProjectConfig(array $existing, array $patches): array
    {
        $merged = $existing;
        foreach ($patches as $inputKey => $value) {
            if (! isset(ProjectStudConfigFieldMap::INPUT_TO_YAML[$inputKey])) {
                continue;
            }
            $merged[ProjectStudConfigFieldMap::INPUT_TO_YAML[$inputKey]] = $value;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptProjectKey(array $existing): array
    {
        $current = isset($existing['projectKey']) ? (string) $existing['projectKey'] : '';
        $answer = $this->prompt->ask(
            MessageRef::key('config.project_init.prompt_project_key'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['projectKey' => strtoupper(trim((string) $answer))];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptLabelDefaults(array $existing): array
    {
        return array_merge(
            $this->promptStoredLabelList(
                $existing,
                ProjectStudConfigKeys::WORK_ITEM_LABELS,
                'workItemLabels',
                'config.project_init.prompt_work_item_labels',
            ),
            $this->promptStoredLabelList(
                $existing,
                ProjectStudConfigKeys::PULL_REQUEST_LABELS,
                'pullRequestLabels',
                'config.project_init.prompt_pull_request_labels',
            ),
        );
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptStoredLabelList(array $existing, string $yamlKey, string $inputKey, string $messageKey): array
    {
        $stored = ProjectStudConfigKeys::readLabelList($existing, $yamlKey);
        $joined = $stored === [] ? null : implode(',', $stored);
        $questionKey = $stored === [] ? $messageKey : $messageKey . '_stored';
        $answer = $this->prompt->ask(MessageRef::key($questionKey), $joined);
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        $normalized = ProjectStudConfigKeys::normalizeLabelList((string) $answer);
        if ($normalized === [] || $normalized === $stored) {
            return [];
        }

        return [$inputKey => $normalized];
    }
}
