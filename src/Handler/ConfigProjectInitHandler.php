<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\ProjectStudConfigFieldMap;
use App\Config\SecretKeyPolicy;
use App\Contract\WorkflowEntryRecorder;
use App\DTO\WorkflowRecorder;
use App\Response\ConfigProjectInitResponse;
use App\Service\GitRepository;
use App\Service\GitSetupService;

/**
 * Creates or merges project-level stud config (.git/stud.config) via agent JSON or interactive prompts.
 */
class ConfigProjectInitHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitSetupService $gitSetupService,
        private readonly ConfigProjectInitPromptCollector $promptCollector,
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
            $reserved = $this->findReservedAgentKeys($rawAgentInput);
            if ($reserved !== []) {
                return ConfigProjectInitResponse::error('config.project_init.reserved_keys', [
                    '%keys%' => implode(', ', $reserved),
                ]);
            }
            $unknown = $this->findUnknownAgentKeys($rawAgentInput);
            if ($unknown !== []) {
                return ConfigProjectInitResponse::error('config.project_init.unknown_keys', [
                    '%keys%' => implode(', ', $unknown),
                ]);
            }
            $transitionErr = $this->validateAgentTransitionIdInput($rawAgentInput);
            if ($transitionErr !== null) {
                return ConfigProjectInitResponse::error($transitionErr);
            }
        }

        $patches = $isAgent
            ? $this->extractAgentValuePatches($rawAgentInput)
            : $this->gatherInteractivePatches($recorder ?? new WorkflowRecorder());
        $this->normalizeInputKeyPatches($patches);

        $yamlPatches = $this->normalizeToYamlPatches($patches);
        $this->normalizeBaseBranchStorageForm($yamlPatches);
        $yamlPatches = $this->dropWhitespaceOnlyStringPatches($yamlPatches);
        if ($yamlPatches === []) {
            $existing = $this->gitRepository->readProjectConfig();

            return ConfigProjectInitResponse::success(false, SecretKeyPolicy::redact($existing));
        }

        $normalizedError = $this->validateYamlPatches($yamlPatches);
        if ($normalizedError !== null) {
            return $normalizedError;
        }

        if (isset($yamlPatches['baseBranch']) && ! $skipBaseBranchRemoteCheck) {
            try {
                $this->gitSetupService->validateBaseBranchOnRemote((string) $yamlPatches['baseBranch']);
            } catch (\RuntimeException $e) {
                return ConfigProjectInitResponse::error('config.project_init.base_branch_error', [
                    '%message%' => $e->getMessage(),
                ]);
            }
        }

        $existing = $this->gitRepository->readProjectConfig();
        $merged = $existing;
        foreach ($yamlPatches as $key => $value) {
            $merged[$key] = $value;
        }

        $this->gitRepository->writeProjectConfig($merged);
        $written = $this->gitRepository->readProjectConfig();

        return ConfigProjectInitResponse::success(true, SecretKeyPolicy::redact($written));
    }

    /**
     * @param array<string, mixed> $rawAgentInput
     * @return list<string>
     */
    protected function findUnknownAgentKeys(array $rawAgentInput): array
    {
        $allowed = ProjectStudConfigFieldMap::allowedInputKeys();
        $unknown = [];
        foreach (array_keys($rawAgentInput) as $key) {
            if (! in_array($key, $allowed, true)) {
                $unknown[] = (string) $key;
            }
        }
        sort($unknown);

        return $unknown;
    }

    /**
     * @param array<string, mixed> $rawAgentInput
     * @return list<string>
     */
    protected function findReservedAgentKeys(array $rawAgentInput): array
    {
        $found = [];
        foreach (ProjectStudConfigFieldMap::RESERVED_YAML_KEYS as $key) {
            if (array_key_exists($key, $rawAgentInput)) {
                $found[] = $key;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @param array<string, mixed> $rawAgentInput
     */
    protected function validateAgentTransitionIdInput(array $rawAgentInput): ?string
    {
        if (! array_key_exists('transitionId', $rawAgentInput)) {
            return null;
        }
        $v = $rawAgentInput['transitionId'];
        if ($v === null) {
            return null;
        }
        if (is_int($v) && $v >= 0) {
            return null;
        }
        if (is_string($v) && ctype_digit(trim($v))) {
            return null;
        }

        return 'config.project_init.invalid_transition_id';
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

        if (isset($yamlPatches['workItemProvider'])) {
            $provider = (string) $yamlPatches['workItemProvider'];
            if (! in_array($provider, ['jira', 'linear', 'auto'], true)) {
                return ConfigProjectInitResponse::error('config.project_init.invalid_work_item_provider');
            }
        }

        if (isset($yamlPatches['linearTypeBranchPrefixes'])) {
            $invalid = $this->validateLinearTypeBranchPrefixes($yamlPatches['linearTypeBranchPrefixes']);
            if ($invalid !== null) {
                return $invalid;
            }
        }

        return null;
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

    /**
     * @param array<string, mixed> $rawAgentInput
     * @return array<string, mixed>
     */
    protected function extractAgentValuePatches(array $rawAgentInput): array
    {
        $out = [];
        foreach (array_keys(ProjectStudConfigFieldMap::INPUT_TO_YAML) as $key) {
            if (! array_key_exists($key, $rawAgentInput)) {
                continue;
            }
            $value = $rawAgentInput[$key];
            if ($value === null) {
                continue;
            }
            $coerced = $this->coerceAgentValue($key, $value);
            if (is_string($coerced) && trim($coerced) === '') {
                continue;
            }
            if (is_array($coerced) && $coerced === []) {
                continue;
            }
            $out[$key] = $coerced;
        }

        return $out;
    }

    protected function coerceAgentValue(string $key, mixed $value): mixed
    {
        if ($key === 'transitionId') {
            return (int) $value;
        }

        if ($key === 'gitProvider' && is_string($value)) {
            return strtolower(trim($value));
        }

        if ($key === 'workItemProvider' && is_string($value)) {
            return strtolower(trim($value));
        }

        if ($key === 'linearTypeBranchPrefixes' && is_array($value)) {
            return $this->normalizeLinearTypeBranchPrefixes($value);
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $prefixes
     * @return array<string, string>
     */
    protected function normalizeLinearTypeBranchPrefixes(array $prefixes): array
    {
        $out = [];
        foreach ($prefixes as $typeLabel => $prefix) {
            if (! is_string($typeLabel) || ! is_string($prefix)) {
                continue;
            }
            $label = trim($typeLabel);
            $normalizedPrefix = trim($prefix);
            if ($label === '' || $normalizedPrefix === '') {
                continue;
            }
            $out[$label] = $normalizedPrefix;
        }

        return $out;
    }
}
