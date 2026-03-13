<?php

declare(strict_types=1);

namespace App\Service;

class GitSetupService
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly Logger $logger,
        private readonly TranslationService $translator
    ) {
    }

    /**
     * Ensures the base branch is configured in project config.
     * If not configured, attempts auto-detection and prompts user if needed.
     * Validates that the configured branch exists on remote before saving.
     *
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io The Symfony IO instance
     * @param bool $quiet When true, use DEFAULT_BASE_BRANCH if not configured (no prompt); fail if invalid
     * @return string The base branch name with 'origin/' prefix
     * @throws \RuntimeException If not in a git repository or if base branch validation fails
     */
    public function ensureBaseBranchConfigured(
        \Symfony\Component\Console\Style\SymfonyStyle $io,
        bool $quiet = false
    ): string {
        try {
            $this->gitRepository->getProjectConfigPath();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($this->translator->trans('config.base_branch_required'));
        }

        $config = $this->gitRepository->readProjectConfig();
        $baseBranch = $config['baseBranch'] ?? null;

        $validConfigured = $this->validateConfiguredBaseBranch($baseBranch, $quiet);
        if ($validConfigured !== null) {
            return $validConfigured;
        }

        if ($quiet) {
            return $this->resolveDefaultBaseBranchQuiet();
        }

        return $this->promptAndSaveBaseBranch($config);
    }

    protected function validateConfiguredBaseBranch(
        mixed $baseBranch,
        bool $quiet
    ): ?string {
        if ($baseBranch === null || ! is_string($baseBranch) || $baseBranch === '') {
            return null;
        }
        $branchName = str_replace('origin/', '', $baseBranch);
        if (! $this->gitRepository->remoteBranchExists('origin', $branchName)) {
            if ($quiet) {
                throw new \RuntimeException($this->translator->trans('config.base_branch_invalid', ['branch' => $branchName]));
            }
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.base_branch_invalid', ['branch' => $branchName]));

            return null;
        }

        return str_starts_with($baseBranch, 'origin/') ? $baseBranch : 'origin/' . $baseBranch;
    }

    protected function resolveDefaultBaseBranchQuiet(): string
    {
        $defaultBranch = defined('DEFAULT_BASE_BRANCH') ? DEFAULT_BASE_BRANCH : 'origin/develop';
        $branchName = str_replace('origin/', '', $defaultBranch);
        if ($this->gitRepository->remoteBranchExists('origin', $branchName)) {
            return str_starts_with($defaultBranch, 'origin/') ? $defaultBranch : 'origin/' . $defaultBranch;
        }

        throw new \RuntimeException(
            'Base branch is not configured and default branch "' . $branchName . '" does not exist on remote. Run without --quiet to configure, or run "stud config:init".'
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function promptAndSaveBaseBranch(array $config): string
    {
        $detected = $this->detectBaseBranch();
        $defaultSuggestion = $detected ?? 'develop';
        if ($detected !== null) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.base_branch_detected', ['branch' => $detected]));
        }
        $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.base_branch_not_configured'));
        $enteredBranch = $this->logger->ask(
            $this->translator->trans('config.base_branch_prompt'),
            $defaultSuggestion,
            function (?string $value): string {
                if (empty(trim($value ?? ''))) {
                    throw new \RuntimeException('Base branch name cannot be empty.');
                }

                return trim($value);
            }
        );
        if ($enteredBranch === null || trim($enteredBranch) === '') {
            throw new \RuntimeException($this->translator->trans('config.base_branch_required'));
        }
        $enteredBranch = trim($enteredBranch);
        if (! $this->gitRepository->remoteBranchExists('origin', $enteredBranch)) {
            throw new \RuntimeException($this->translator->trans('config.base_branch_invalid', ['branch' => $enteredBranch]));
        }
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.base_branch_saving'));
        $config['baseBranch'] = $enteredBranch;
        $this->gitRepository->writeProjectConfig($config);
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.base_branch_saved', ['branch' => $enteredBranch]));

        return 'origin/' . $enteredBranch;
    }

    /**
     * Gets the configured base branch from project config.
     * Returns the branch name with 'origin/' prefix for consistency with git commands.
     *
     * @return string The base branch name with 'origin/' prefix
     * @throws \RuntimeException If base branch is not configured and cannot be auto-detected
     */
    protected function getBaseBranch(): string
    {
        $config = $this->gitRepository->readProjectConfig();
        $baseBranchValue = $config['baseBranch'] ?? null;
        if ($baseBranchValue !== null && is_string($baseBranchValue) && trim($baseBranchValue) !== '') {
            $baseBranch = $baseBranchValue;
            if (! str_starts_with($baseBranch, 'origin/')) {
                return 'origin/' . $baseBranch;
            }

            return $baseBranch;
        }

        $detected = $this->detectBaseBranch();
        if ($detected !== null) {
            return 'origin/' . $detected;
        }

        throw new \RuntimeException('Base branch not configured and could not be auto-detected.');
    }

    /**
     * Auto-detects the most likely base branch from remote branches.
     * Checks branches in priority order: develop, main, master, dev, trunk.
     *
     * @return string|null The detected base branch name (without origin/ prefix), or null if none found
     */
    protected function detectBaseBranch(): ?string
    {
        $candidates = ['develop', 'main', 'master', 'dev', 'trunk'];
        $remoteBranches = $this->gitBranchService->getAllRemoteBranches('origin');
        $remoteBranchesSet = array_flip($remoteBranches);

        foreach ($candidates as $candidate) {
            if (isset($remoteBranchesSet[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Ensures the git provider is configured in project config.
     * If not configured, attempts auto-detection from remote URL and prompts user if needed.
     *
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io The Symfony IO instance
     * @param bool $quiet When true, use auto-detected provider or throw; do not prompt
     * @return string The provider type ('github' or 'gitlab')
     * @throws \RuntimeException If not in a git repository or if provider cannot be determined
     */
    public function ensureGitProviderConfigured(
        \Symfony\Component\Console\Style\SymfonyStyle $io,
        bool $quiet = false
    ): string {
        try {
            $this->gitRepository->getProjectConfigPath();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($this->translator->trans('config.git_provider_required'));
        }

        $config = $this->gitRepository->readProjectConfig();
        $provider = $config['gitProvider'] ?? null;

        if (is_string($provider) && in_array($provider, ['github', 'gitlab'], true)) {
            return $provider;
        }

        $parsed = $this->gitRepository->parseGitUrl('origin');
        $detected = $parsed['provider'] ?? null;

        if ($quiet) {
            if ($detected !== null && in_array($detected, ['github', 'gitlab'], true)) {
                return $detected;
            }

            throw new \RuntimeException(
                'Git provider is not configured and could not be auto-detected from remote. Run without --quiet to configure, or run "stud config:init".'
            );
        }

        return $this->promptAndSaveGitProvider($config, $detected);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function promptAndSaveGitProvider(array $config, ?string $detected): string
    {
        if ($detected !== null) {
            $this->logger->note(
                Logger::VERBOSITY_NORMAL,
                $this->translator->trans('config.git_provider_detected', ['provider' => $detected])
            );
        }

        $this->logger->note(
            Logger::VERBOSITY_NORMAL,
            $this->translator->trans('config.git_provider_not_configured')
        );

        $enteredProvider = $this->logger->choice(
            $this->translator->trans('config.git_provider_prompt'),
            ['github', 'gitlab'],
            $detected ?? 'github'
        );

        if ($enteredProvider === null || ! in_array($enteredProvider, ['github', 'gitlab'], true)) {
            throw new \RuntimeException($this->translator->trans('config.git_provider_required'));
        }

        $this->logger->text(
            Logger::VERBOSITY_NORMAL,
            $this->translator->trans('config.git_provider_saving')
        );

        $config['gitProvider'] = $enteredProvider;
        $this->gitRepository->writeProjectConfig($config);

        $this->logger->success(
            Logger::VERBOSITY_NORMAL,
            $this->translator->trans('config.git_provider_saved', ['provider' => $enteredProvider])
        );

        return $enteredProvider;
    }

    /**
     * Ensures the git token is configured for the given provider.
     * Checks project config first, then global config.
     * If not found, prompts user to configure it (unless quiet).
     *
     * @param string $providerType The provider type ('github' or 'gitlab')
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io The Symfony IO instance
     * @param array<string, mixed> $globalConfig The global configuration array
     * @param bool $quiet When true, do not prompt; return null if token missing
     * @return string|null The token string, or null if user skipped or error occurred (or missing when quiet)
     * @throws \RuntimeException If not in a git repository
     */
    public function ensureGitTokenConfigured(
        string $providerType,
        \Symfony\Component\Console\Style\SymfonyStyle $io,
        array $globalConfig,
        bool $quiet = false
    ): ?string {
        try {
            $this->gitRepository->getProjectConfigPath();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($this->translator->trans('config.git_token_required'));
        }

        $projectConfig = $this->gitRepository->readProjectConfig();
        $keys = $this->getGitTokenKeysForProvider($providerType);

        $token = $this->resolveGitTokenFromConfig($projectConfig, $globalConfig, $keys);
        if ($token !== null) {
            return $token;
        }

        $this->warnGitTokenTypeMismatchIfOppositePresent($projectConfig, $globalConfig, $keys, $providerType);

        if ($quiet) {
            return null;
        }

        return $this->promptAndSaveGitToken($providerType, $projectConfig, $globalConfig, $keys);
    }

    /**
     * @return array{tokenKey: string, globalTokenKey: string, oppositeTokenKey: string, oppositeLocalKey: string, oppositeProvider: string}
     */
    protected function getGitTokenKeysForProvider(string $providerType): array
    {
        $isGitHub = $providerType === 'github';

        return [
            'tokenKey' => $isGitHub ? 'githubToken' : 'gitlabToken',
            'globalTokenKey' => $isGitHub ? 'GITHUB_TOKEN' : 'GITLAB_TOKEN',
            'oppositeTokenKey' => $isGitHub ? 'GITLAB_TOKEN' : 'GITHUB_TOKEN',
            'oppositeLocalKey' => $isGitHub ? 'gitlabToken' : 'githubToken',
            'oppositeProvider' => $isGitHub ? 'GitLab' : 'GitHub',
        ];
    }

    /**
     * @param array<string, mixed> $projectConfig
     * @param array<string, mixed> $globalConfig
     * @param array{tokenKey: string, globalTokenKey: string} $keys
     */
    protected function resolveGitTokenFromConfig(array $projectConfig, array $globalConfig, array $keys): ?string
    {
        $token = $projectConfig[$keys['tokenKey']] ?? null;
        if ($token !== null && is_string($token) && trim($token) !== '') {
            return trim($token);
        }
        $globalToken = $globalConfig[$keys['globalTokenKey']] ?? null;
        if ($globalToken !== null && is_string($globalToken) && trim($globalToken) !== '') {
            return trim($globalToken);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $projectConfig
     * @param array<string, mixed> $globalConfig
     * @param array{oppositeTokenKey: string, oppositeLocalKey: string, oppositeProvider: string} $keys
     */
    protected function warnGitTokenTypeMismatchIfOppositePresent(
        array $projectConfig,
        array $globalConfig,
        array $keys,
        string $providerType
    ): void {
        $oppositeToken = $projectConfig[$keys['oppositeLocalKey']] ?? $globalConfig[$keys['oppositeTokenKey']] ?? null;
        if ($oppositeToken === null || ! is_string($oppositeToken) || trim($oppositeToken) === '') {
            return;
        }
        $this->logger->warning(
            Logger::VERBOSITY_NORMAL,
            $this->translator->trans('config.git_token_type_mismatch', [
                'provider' => ucfirst($providerType),
                'opposite' => $keys['oppositeProvider'],
            ])
        );
    }

    /**
     * @param array<string, mixed> $projectConfig
     * @param array<string, mixed> $globalConfig
     * @param array{tokenKey: string} $keys
     */
    protected function promptAndSaveGitToken(
        string $providerType,
        array $projectConfig,
        array $globalConfig,
        array $keys
    ): ?string {
        $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.git_token_not_configured'));
        if (($globalConfig['GITHUB_TOKEN'] ?? null) === null && ($globalConfig['GITLAB_TOKEN'] ?? null) === null) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.git_token_global_suggestion'));
        }
        $enteredToken = $this->logger->askHidden($this->translator->trans('config.git_token_prompt', ['provider' => ucfirst($providerType)]));
        if ($enteredToken === null || trim($enteredToken) === '') {
            return null;
        }
        $enteredToken = trim($enteredToken);
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.git_token_saving'));
        $projectConfig[$keys['tokenKey']] = $enteredToken;
        $this->gitRepository->writeProjectConfig($projectConfig);
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.git_token_saved', ['provider' => ucfirst($providerType)]));

        return $enteredToken;
    }
}
