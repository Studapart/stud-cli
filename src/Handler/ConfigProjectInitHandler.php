<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\AgentInputAliases;
use App\Config\EffectiveIssueTrackerCredentials;
use App\Config\ProjectStudConfigFieldMap;
use App\Config\ProjectStudConfigKeys;
use App\Contract\WorkflowEntryRecorder;
use App\DTO\WorkflowRecorder;
use App\Enum\IssueTrackerProvider;
use App\Response\ConfigProjectInitResponse;
use App\Service\GitRepository;

/**
 * Creates or merges project-level stud config (.git/stud.config) via agent JSON or interactive prompts.
 */
class ConfigProjectInitHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ConfigProjectInitPatchWriter $patchWriter,
        private readonly ConfigProjectInitPromptCollector $promptCollector,
        private readonly ConfigProjectInitAgentInput $agentInput,
    ) {
    }

    /**
     * @param array<string, mixed> $rawAgentInput Decoded agent JSON (empty when not agent)
     */
    public function handle(
        array $rawAgentInput,
        bool $skipBaseBranchRemoteCheck,
        bool $isAgent,
        ?WorkflowEntryRecorder $recorder = null,
    ): ConfigProjectInitResponse {
        try {
            $this->gitRepository->getProjectConfigPath();
        } catch (\RuntimeException) {
            return ConfigProjectInitResponse::error('config.project_init.not_git_repository');
        }

        if ($isAgent) {
            $rawAgentInput = AgentInputAliases::normalize($rawAgentInput);
            $agentInputError = $this->agentInput->validate($rawAgentInput);
            if ($agentInputError !== null) {
                return $agentInputError;
            }
        }

        $patches = $isAgent
            ? $this->agentInput->patches($rawAgentInput)
            : $this->gatherInteractivePatches($recorder ?? new WorkflowRecorder());
        $this->normalizeInputKeyPatches($patches);

        $yamlPatches = $this->normalizeToYamlPatches($patches);
        $this->normalizeBaseBranchStorageForm($yamlPatches);
        $yamlPatches = $this->dropWhitespaceOnlyStringPatches($yamlPatches);
        $validationError = $this->validateYamlPatches($yamlPatches);
        if ($validationError !== null) {
            return $validationError;
        }

        return $this->patchWriter->write($yamlPatches, $skipBaseBranchRemoteCheck);
    }

    /**
     * @param array<string, mixed> $yamlPatches
     */
    protected function normalizeBaseBranchStorageForm(array &$yamlPatches): void
    {
        if (! isset($yamlPatches['baseBranch'])) {
            return;
        }
        $b = (string) $yamlPatches['baseBranch'];
        $yamlPatches['baseBranch'] = str_replace('origin/', '', $b);
    }

    /**
     * @param array<string, mixed> $patches
     */
    protected function normalizeInputKeyPatches(array &$patches): void
    {
        $patches = (new EffectiveIssueTrackerCredentials())->normalizeInputPatches($patches);
        if (isset($patches['projectKey']) && is_string($patches['projectKey'])) {
            $patches['projectKey'] = strtoupper(trim($patches['projectKey']));
        }
        if (isset($patches['jiraDefaultProject']) && is_string($patches['jiraDefaultProject'])) {
            $patches['jiraDefaultProject'] = strtoupper(trim($patches['jiraDefaultProject']));
        }
    }

    /**
     * Drops string patches that are empty or whitespace-only so we never overwrite stored values
     * (same spirit as config:init, which omits empty strings when saving global config).
     *
     * @param array<string, mixed> $yamlPatches
     * @return array<string, mixed>
     */
    protected function dropWhitespaceOnlyStringPatches(array $yamlPatches): array
    {
        $out = [];
        foreach ($yamlPatches as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $patches Input-keyed patches
     * @return array<string, mixed> YAML-keyed patches
     */
    protected function normalizeToYamlPatches(array $patches): array
    {
        $out = [];
        foreach ($patches as $inputKey => $value) {
            if (! isset(ProjectStudConfigFieldMap::INPUT_TO_YAML[$inputKey])) {
                continue;
            }
            $yamlKey = ProjectStudConfigFieldMap::INPUT_TO_YAML[$inputKey];
            $out[$yamlKey] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $yamlPatches
     */
    protected function validateYamlPatches(array $yamlPatches): ?ConfigProjectInitResponse
    {
        if (isset($yamlPatches['gitProvider'])) {
            $p = (string) $yamlPatches['gitProvider'];
            if (! in_array($p, ['github', 'gitlab'], true)) {
                return ConfigProjectInitResponse::error('config.project_init.invalid_git_provider');
            }
        }

        if (isset($yamlPatches['transitionId'])) {
            $id = $yamlPatches['transitionId'];
            if (! is_int($id) || $id < 0) {
                return ConfigProjectInitResponse::error('config.project_init.invalid_transition_id');
            }
        }

        if (isset($yamlPatches[ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER])) {
            $provider = (string) $yamlPatches[ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER];
            if (! IssueTrackerProvider::isProjectConfigValue($provider)) {
                return ConfigProjectInitResponse::error('config.project_init.invalid_issue_tracker_provider');
            }
        }

        if (isset($yamlPatches['linearTypeBranchPrefixes'])) {
            $invalid = $this->validateLinearTypeBranchPrefixes($yamlPatches['linearTypeBranchPrefixes']);
            if ($invalid !== null) {
                return $invalid;
            }
        }

        return $this->validateConfiguredLabelLists($yamlPatches);
    }

    /**
     * @param array<string, mixed> $yamlPatches
     */
    protected function validateConfiguredLabelLists(array $yamlPatches): ?ConfigProjectInitResponse
    {
        foreach ([ProjectStudConfigKeys::WORK_ITEM_LABELS, ProjectStudConfigKeys::PULL_REQUEST_LABELS] as $key) {
            if (! isset($yamlPatches[$key])) {
                continue;
            }
            if (! $this->isNonEmptyStringList($yamlPatches[$key])) {
                return ConfigProjectInitResponse::error('config.project_init.invalid_label_list');
            }
        }

        return null;
    }

    protected function isNonEmptyStringList(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }
        foreach ($value as $label) {
            if (! is_string($label) || trim($label) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $prefixes
     */
    protected function validateLinearTypeBranchPrefixes(mixed $prefixes): ?ConfigProjectInitResponse
    {
        if (! is_array($prefixes)) {
            return ConfigProjectInitResponse::error('config.project_init.invalid_linear_type_branch_prefixes');
        }

        foreach ($prefixes as $typeLabel => $prefix) {
            if (! is_string($typeLabel) || trim($typeLabel) === '') {
                return ConfigProjectInitResponse::error('config.project_init.invalid_linear_type_branch_prefixes');
            }
            if (! is_string($prefix) || trim($prefix) === '') {
                return ConfigProjectInitResponse::error('config.project_init.invalid_linear_type_branch_prefixes');
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed> input-keyed patches
     */
    protected function gatherInteractivePatches(WorkflowEntryRecorder $recorder): array
    {
        return $this->promptCollector->collect($recorder);
    }
}
