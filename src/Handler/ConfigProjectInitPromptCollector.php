<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\ProjectStudConfigFieldMap;
use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Enum\WorkItemProvider;
use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use App\Service\GitTokenPromptResolver;
use App\Service\GlobalConfigProviderResolver;
use App\Service\ProjectMetadataPromptService;
use App\Service\Prompt\PromptInterface;

/**
 * Interactive prompts for stud config:project-init (keeps ConfigProjectInitHandler within size limits).
 */
class ConfigProjectInitPromptCollector
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitSetupService $gitSetupService,
        mixed $_translator,
        private readonly PromptInterface $prompt,
        private readonly GitTokenPromptResolver $gitTokenPromptResolver,
        private readonly FileSystem $fileSystem,
        private readonly string $globalConfigPath,
        private readonly GlobalConfigProviderResolver $providerResolver,
        private readonly ProjectMetadataPromptService $metadataPrompts,
    ) {
        unset($_translator);
    }

    /**
     * @return array<string, mixed> input-keyed patches
     */
    public function collect(WorkflowEntryRecorder $recorder): array
    {
        $existing = $this->gitRepository->readProjectConfig();
        $globalWorkItemProviders = $this->readGlobalWorkItemProviders();
        $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.project_init.interactive_title'));
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.project_init.interactive_hint'));

        $patches = [];
        if ($this->providerResolver->collectsJira($globalWorkItemProviders)
            && $this->providerResolver->collectsLinear($globalWorkItemProviders)) {
            $patches = array_merge($patches, $this->promptWorkItemProvider($existing));
        }

        $effectiveProvider = $this->resolveEffectiveWorkItemProvider(
            $this->mergeProjectConfig($existing, $patches),
            $globalWorkItemProviders,
        );

        $patches = array_merge($patches, $this->promptProjectKey($existing));
        $mergedAfterProjectKey = $this->mergeProjectConfig($existing, $patches);
        if ($this->shouldRunJiraPrompts($effectiveProvider)) {
            $patches = array_merge($patches, $this->promptJiraDefaultProject($existing));
            $patches = array_merge($patches, $this->promptConfluenceDefaultSpace($existing));
            $patches = array_merge($patches, $this->promptTransitionFromWorkflow($mergedAfterProjectKey, $recorder));
        }
        if ($this->shouldRunLinearPrompts($effectiveProvider)) {
            $patches = array_merge($patches, $this->promptLinearFields($mergedAfterProjectKey, $recorder));
        }
        $patches = array_merge($patches, $this->promptBaseBranch($existing, $recorder));
        $patches = array_merge($patches, $this->promptGitProvider($existing, $recorder));
        $patches = array_merge($patches, $this->promptGitlabInstanceUrl($existing));
        $patches = array_merge($patches, $this->promptGithubToken($existing, $recorder));
        $patches = array_merge($patches, $this->promptGitlabToken($existing, $recorder));

        return $patches;
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptWorkItemProvider(array $existing): array
    {
        $current = isset($existing['workItemProvider']) && is_string($existing['workItemProvider'])
            ? strtolower(trim($existing['workItemProvider']))
            : 'auto';
        if (! in_array($current, ['jira', 'linear', 'auto'], true)) {
            $current = 'auto';
        }
        $choice = $this->prompt->choice(
            MessageRef::key('config.project_init.prompt_work_item_provider'),
            ['jira', 'linear', 'auto'],
            $current,
        );

        return ['workItemProvider' => (string) $choice];
    }

    /**
     * @param array<string, mixed> $mergedConfig
     * @return array<string, mixed>
     */
    protected function promptLinearFields(array $mergedConfig, WorkflowEntryRecorder $recorder): array
    {
        $projectKey = $this->resolveProjectKey($mergedConfig);
        if ($projectKey === null) {
            return [];
        }

        $patches = [];
        $startStateId = $this->metadataPrompts->chooseLinearStartStateId($recorder, $projectKey, $mergedConfig);
        if ($startStateId !== null) {
            $patches['linearStartStateId'] = $startStateId;
        }

        $labelGroupId = $this->metadataPrompts->chooseLinearTypeLabelGroupId($recorder, $projectKey, $mergedConfig);
        if ($labelGroupId !== null) {
            $patches['linearTypeLabelGroupId'] = $labelGroupId;
            /** @var array<string, string>|null $branchPrefixes */
            $branchPrefixes = $this->metadataPrompts->buildLinearBranchPrefixMap(
                $recorder,
                $projectKey,
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
     * @param array<string, mixed> $mergedConfig
     * @return array<string, mixed>
     */
    protected function promptTransitionFromWorkflow(array $mergedConfig, WorkflowEntryRecorder $recorder): array
    {
        $projectKey = $this->resolveProjectKey($mergedConfig);
        if ($projectKey === null) {
            return [];
        }

        $transitionId = $this->metadataPrompts->chooseJiraTransitionId($recorder, $projectKey, $mergedConfig);
        if ($transitionId === null) {
            return [];
        }

        return ['transitionId' => $transitionId];
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

    /**
     * @return list<string>
     */
    protected function readGlobalWorkItemProviders(): array
    {
        if (! $this->fileSystem->fileExists($this->globalConfigPath)) {
            return [WorkItemProvider::Jira->value];
        }

        try {
            $config = $this->fileSystem->parseFile($this->globalConfigPath);
        } catch (\Throwable) {
            return [WorkItemProvider::Jira->value];
        }

        if (isset($config['WORK_ITEM_PROVIDERS']) && is_array($config['WORK_ITEM_PROVIDERS'])) {
            $providers = array_values(array_filter($config['WORK_ITEM_PROVIDERS'], 'is_string'));

            return $this->providerResolver->normalizeWorkItemProviders($providers);
        }

        return $this->providerResolver->inferDefaultWorkItemProviders($config);
    }

    /**
     * @param array<string, mixed> $existing
     * @param list<string>         $globalWorkItemProviders
     */
    protected function resolveEffectiveWorkItemProvider(array $existing, array $globalWorkItemProviders): string
    {
        $hasJira = $this->providerResolver->collectsJira($globalWorkItemProviders);
        $hasLinear = $this->providerResolver->collectsLinear($globalWorkItemProviders);

        if ($hasJira && ! $hasLinear) {
            return 'jira';
        }
        if ($hasLinear && ! $hasJira) {
            return 'linear';
        }

        $stored = isset($existing['workItemProvider']) && is_string($existing['workItemProvider'])
            ? strtolower(trim($existing['workItemProvider']))
            : 'auto';

        return in_array($stored, ['jira', 'linear', 'auto'], true) ? $stored : 'auto';
    }

    protected function shouldRunJiraPrompts(string $effectiveProvider): bool
    {
        return $effectiveProvider === 'jira' || $effectiveProvider === 'auto';
    }

    protected function shouldRunLinearPrompts(string $effectiveProvider): bool
    {
        return $effectiveProvider === 'linear';
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
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptBaseBranch(array $existing, WorkflowEntryRecorder $recorder): array
    {
        $current = isset($existing['baseBranch']) ? (string) $existing['baseBranch'] : '';
        $detected = $this->gitSetupService->detectDefaultBaseBranchName();
        if ($detected !== null) {
            $recorder->addNote(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('config.base_branch_detected', ['branch' => $detected])
            );
        }
        $default = $current !== '' ? $current : ($detected ?? 'develop');
        $answer = $this->prompt->ask(
            MessageRef::key('config.project_init.prompt_base_branch'),
            $default
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['baseBranch' => trim((string) $answer)];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptGitProvider(array $existing, WorkflowEntryRecorder $recorder): array
    {
        $current = isset($existing['gitProvider']) ? (string) $existing['gitProvider'] : '';
        $parsed = $this->gitRepository->parseGitUrl('origin');
        $detected = $parsed['provider'] ?? null;
        if ($current !== '' && in_array($current, ['github', 'gitlab'], true)) {
            return [];
        }
        if ($detected !== null) {
            $recorder->addNote(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('config.git_provider_detected', ['provider' => $detected])
            );
        }
        $choice = $this->prompt->choice(
            MessageRef::key('config.project_init.prompt_git_provider'),
            ['github', 'gitlab'],
            in_array($detected, ['github', 'gitlab'], true) ? $detected : 'github'
        );

        return ['gitProvider' => $choice];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptGitlabInstanceUrl(array $existing): array
    {
        $current = isset($existing['gitlabInstanceUrl']) ? (string) $existing['gitlabInstanceUrl'] : '';
        $answer = $this->prompt->ask(
            MessageRef::key('config.project_init.prompt_gitlab_instance_url'),
            $current !== '' ? $current : null
        );
        if ($answer === null || trim((string) $answer) === '') {
            return [];
        }

        return ['gitlabInstanceUrl' => trim((string) $answer)];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptGithubToken(array $existing, WorkflowEntryRecorder $recorder): array
    {
        $has = isset($existing['githubToken']) && is_string($existing['githubToken']) && trim($existing['githubToken']) !== '';
        $recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.project_init.prompt_github_token_hint'));
        $answer = $this->prompt->askHidden(MessageRef::key('config.project_init.prompt_github_token'));
        if ($has && $answer !== null && trim((string) $answer) === '.') {
            return [];
        }
        $token = $this->gitTokenPromptResolver->tokenFromUserInput($answer === null ? null : (string) $answer);
        if ($token === null) {
            return [];
        }

        return ['githubToken' => $token];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    protected function promptGitlabToken(array $existing, WorkflowEntryRecorder $recorder): array
    {
        $has = isset($existing['gitlabToken']) && is_string($existing['gitlabToken']) && trim($existing['gitlabToken']) !== '';
        $recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('config.project_init.prompt_gitlab_token_hint'));
        $answer = $this->prompt->askHidden(MessageRef::key('config.project_init.prompt_gitlab_token'));
        if ($has && $answer !== null && trim((string) $answer) === '.') {
            return [];
        }
        $token = $this->gitTokenPromptResolver->tokenFromUserInput($answer === null ? null : (string) $answer);
        if ($token === null) {
            return [];
        }

        return ['gitlabToken' => $token];
    }
}
