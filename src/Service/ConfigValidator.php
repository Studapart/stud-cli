<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ValidationResult;

/**
 * Service that validates command configuration requirements and prompts for missing keys.
 * Supports auto-detection for common configuration keys.
 */
class ConfigValidator
{
    /**
     * Command requirements metadata.
     * Defines which global and project keys are required for each command.
     *
     * @var array<string, array{required_global: array<string>, required_project: array<string>}>
     */
    private const COMMAND_REQUIREMENTS = [
        'items:list' => [
            'required_global' => ['JIRA_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN'],
            'required_project' => [],
        ],
        'items:start' => [
            'required_global' => ['JIRA_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN'],
            'required_project' => ['baseBranch'], // Can be auto-detected
        ],
        'submit' => [
            'required_global' => ['GITHUB_TOKEN'], // or GITLAB_TOKEN
            'required_project' => ['baseBranch'],
        ],
        // Add more commands as needed
    ];

    public function __construct(
        private readonly Logger $logger,
        private readonly TranslationService $translator,
        private readonly ?GitRepository $gitRepository = null
    ) {
    }

    /**
     * Validates command requirements against the provided configuration.
     *
     * @param string $commandName The command name to validate
     * @param array<string, mixed> $globalConfig The global configuration
     * @param array<string, mixed>|null $projectConfig The project configuration (null if not in git repo)
     * @return ValidationResult The validation result
     */
    public function validateCommandRequirements(string $commandName, array $globalConfig, ?array $projectConfig): ValidationResult
    {
        $requirements = self::COMMAND_REQUIREMENTS[$commandName] ?? [
            'required_global' => [],
            'required_project' => [],
        ];

        $missingGlobalKeys = $this->findMissingKeys($requirements['required_global'], $globalConfig);
        $missingProjectKeys = $this->findMissingKeys(
            $requirements['required_project'],
            $projectConfig ?? []
        );

        $canProceed = empty($missingGlobalKeys) && empty($missingProjectKeys);

        return new ValidationResult($missingGlobalKeys, $missingProjectKeys, $canProceed);
    }

    /**
     * Prompts for missing configuration keys interactively.
     *
     * @param array<string> $missingKeys The missing keys to prompt for
     * @param string $scope The scope ('global' or 'project')
     * @return array<string, mixed> The values provided by the user
     */
    public function promptForMissingKeys(array $missingKeys, string $scope): array
    {
        $values = [];

        foreach ($missingKeys as $key) {
            // Try auto-detection first
            $autoDetected = $this->autoDetectKey($key);
            if ($autoDetected !== null) {
                $this->logger->note(
                    Logger::VERBOSITY_NORMAL,
                    $this->translator->trans('config.auto_detected', ['key' => $key, 'value' => $autoDetected])
                );
                $values[$key] = $autoDetected;

                continue;
            }

            // Prompt user
            $prompt = $scope === 'global'
                ? $this->translator->trans('config.missing_global_key', ['key' => $key])
                : $this->translator->trans('config.missing_project_key', ['key' => $key]);

            $value = $this->logger->ask($prompt, null);
            if ($value !== null && trim($value) !== '') {
                $values[$key] = trim($value);
            }
        }

        return $values;
    }

    /**
     * Auto-detects common configuration keys when possible.
     *
     * @param string $key The configuration key to detect
     * @return string|null The detected value, or null if detection is not possible
     */
    public function autoDetectKey(string $key): ?string
    {
        if ($key === 'baseBranch' && $this->gitRepository !== null) {
            return $this->autoDetectBaseBranch();
        }

        return null;
    }

    /**
     * Auto-detects the base branch by scanning git branches.
     * Checks for 'develop', 'main', 'master' in that order.
     *
     * @return string|null The detected base branch name, or null if not found
     */
    protected function autoDetectBaseBranch(): ?string
    {
        if ($this->gitRepository === null) {
            return null;
        }

        try {
            $candidates = ['develop', 'main', 'master'];
            $remoteBranches = $this->gitRepository->getAllRemoteBranches('origin');

            foreach ($candidates as $candidate) {
                if (in_array($candidate, $remoteBranches, true)) {
                    return $candidate;
                }
            }
        } catch (\Throwable $e) {
            // Auto-detection failed, return null
            return null;
        }

        return null;
    }

    /**
     * Finds missing keys by comparing required keys with actual config keys.
     *
     * @param array<string> $requiredKeys The required keys
     * @param array<string, mixed> $config The actual configuration
     * @return array<string> The missing keys
     */
    protected function findMissingKeys(array $requiredKeys, array $config): array
    {
        $missing = [];

        foreach ($requiredKeys as $key) {
            if (! isset($config[$key]) || empty(trim((string) ($config[$key] ?? '')))) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
