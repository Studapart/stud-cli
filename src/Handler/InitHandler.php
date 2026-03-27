<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\FileSystem;
use App\Service\GitTokenPromptResolver;
use App\Service\InitProjectConfigFollowUpService;
use App\Service\Logger;
use App\Service\MigrationRegistry;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

class InitHandler
{
    public function __construct(
        private readonly FileSystem $fileSystem,
        private readonly string $configPath,
        private readonly TranslationService $translator,
        private readonly Logger $logger,
        private readonly GitTokenPromptResolver $gitTokenPromptResolver,
        private readonly ?InitProjectConfigFollowUpService $projectConfigFollowUp = null,
    ) {
    }

    /**
     * @param bool $isAgentMode When true, skips post-save project follow-up (same as `stud config:init --agent`).
     * @param bool $isInteractiveCli From Castor input; when false, project follow-up only shows the run-later note.
     */
    public function handle(SymfonyStyle $io, bool $isAgentMode = false, bool $isInteractiveCli = true): void
    {
        $existingConfig = $this->loadExistingConfig();

        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.wizard.title'));
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.wizard.description', ['path' => $this->configPath]));

        $config = $this->buildConfigFromPrompts($existingConfig);
        $this->applyMigrationVersion($config, $existingConfig);
        $this->saveConfig($config);
        if ($this->projectConfigFollowUp !== null) {
            $this->projectConfigFollowUp->runAfterGlobalSave($io, $isAgentMode, $isInteractiveCli);
        }
        $this->promptForCompletion();
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadExistingConfig(): array
    {
        return $this->fileSystem->fileExists($this->configPath)
            ? $this->fileSystem->parseFile($this->configPath)
            : [];
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @return array<string, mixed>
     */
    protected function buildConfigFromPrompts(array $existingConfig): array
    {
        $languageChoice = $this->promptLanguage($existingConfig);

        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.jira.title'));
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.jira.token_help'));
        $jiraUrlPrompt = $this->translator->trans('config.init.jira.url_prompt');
        $jiraEmailPrompt = $this->translator->trans('config.init.jira.email_prompt');
        $jiraTokenPrompt = $this->translator->trans('config.init.jira.token_prompt');
        $githubTokenPrompt = $this->translator->trans('config.init.git.github_token_prompt');
        $gitlabTokenPrompt = $this->translator->trans('config.init.git.gitlab_token_prompt');

        $jiraUrl = $this->promptRequiredVisible(
            $jiraUrlPrompt,
            $existingConfig['JIRA_URL'] ?? null,
            fn (string $s): string => $this->normalizeJiraUrl($s),
        );
        $jiraEmail = $this->promptRequiredVisible(
            $jiraEmailPrompt,
            $existingConfig['JIRA_EMAIL'] ?? null,
            static fn (string $s): string => $s,
        );
        $jiraToken = $this->promptRequiredJiraApiToken($jiraTokenPrompt, $existingConfig['JIRA_API_TOKEN'] ?? null);

        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.git.title'));
        $this->logger->text(Logger::VERBOSITY_NORMAL, [
            $this->translator->trans('config.init.git.description'),
            $this->translator->trans('config.init.git.token_help'),
            $this->translator->trans('config.init.git.multiple_tokens_note'),
        ]);
        $githubToken = $this->logger->askHidden($githubTokenPrompt);
        $gitlabToken = $this->logger->askHidden($gitlabTokenPrompt);

        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.jira_transition.title'));
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.jira_transition.description'));
        $jiraTransitionEnabled = $this->logger->confirm(
            $this->translator->trans('config.init.jira_transition.prompt'),
            $existingConfig['JIRA_TRANSITION_ENABLED'] ?? false
        );

        return [
            'LANGUAGE' => $languageChoice,
            'JIRA_URL' => $jiraUrl,
            'JIRA_EMAIL' => $jiraEmail,
            'JIRA_API_TOKEN' => $jiraToken,
            'GITHUB_TOKEN' => $this->gitTokenPromptResolver->resolveForGlobalInit($githubToken, 'GITHUB_TOKEN', $existingConfig, $githubTokenPrompt),
            'GITLAB_TOKEN' => $this->gitTokenPromptResolver->resolveForGlobalInit($gitlabToken, 'GITLAB_TOKEN', $existingConfig, $gitlabTokenPrompt),
            'JIRA_TRANSITION_ENABLED' => $jiraTransitionEnabled,
        ];
    }

    /**
     * Trims stored config values; null or whitespace-only becomes null (no stored value).
     */
    protected function nonEmptyStoredString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Null, empty string, or whitespace-only counts as skip (same as empty input after trim).
     */
    protected function isSkippedInput(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        return trim($value) === '';
    }

    protected function normalizeJiraUrl(string $url): string
    {
        return rtrim($url, '/');
    }

    /**
     * Required visible field: skip preserves existing non-empty stored value; first-time skip re-prompts.
     * A value equal to the prompt question text is rejected. Normalizer may yield empty (e.g. URL "/").
     *
     * @param callable(string): string $normalizeValue
     */
    protected function promptRequiredVisible(string $question, ?string $existingStored, callable $normalizeValue): string
    {
        $existing = $this->nonEmptyStoredString($existingStored);
        while (true) {
            $answer = $this->logger->ask($question, $existing);
            if ($this->isSkippedInput($answer)) {
                if ($existing !== null) {
                    return $normalizeValue($existing);
                }

                continue;
            }

            $trimmed = trim((string) $answer);
            if ($trimmed === $question) {
                continue;
            }

            $normalized = $normalizeValue($trimmed);
            if ($normalized === '') {
                if ($existing !== null) {
                    return $normalizeValue($existing);
                }

                continue;
            }

            return $normalized;
        }
    }

    /**
     * Required hidden token: skip preserves existing; no existing value re-prompts. Prompt text never accepted as value.
     */
    protected function promptRequiredJiraApiToken(string $question, ?string $existingStored): string
    {
        $existing = $this->nonEmptyStoredString($existingStored);
        while (true) {
            $answer = $this->logger->askHidden($question);
            if ($this->isSkippedInput($answer)) {
                if ($existing !== null) {
                    return $existing;
                }

                continue;
            }

            $trimmed = trim((string) $answer);
            if ($trimmed === $question) {
                continue;
            }

            return $trimmed;
        }
    }

    /**
     * @param array<string, mixed> $existingConfig
     */
    protected function promptLanguage(array $existingConfig): string
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.language.title'));
        $availableLanguages = ['en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'nl' => 'Dutch', 'ru' => 'Russian', 'el' => 'Greek', 'af' => 'Afrikaans', 'vi' => 'Vietnamese'];
        $defaultLanguage = $existingConfig['LANGUAGE'] ?? $this->detectSystemLocale() ?? 'en';

        $languageOptions = [];
        $languageMap = [];
        foreach ($availableLanguages as $code => $name) {
            $display = "{$name} ({$code})";
            $languageOptions[] = $display;
            $languageMap[$display] = $code;
        }

        $defaultDisplay = $availableLanguages[$defaultLanguage] . ' (' . $defaultLanguage . ')';

        $languageChoiceDisplay = $this->logger->choice(
            $this->translator->trans('config.init.language.prompt'),
            $languageOptions,
            $defaultDisplay
        );

        return $languageMap[$languageChoiceDisplay];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $existingConfig
     */
    protected function applyMigrationVersion(array &$config, array $existingConfig): void
    {
        if (isset($existingConfig['migration_version'])) {
            // Tested via integration test (InitHandlerMigrationVersionIntegrationTest)
            $config['migration_version'] = $existingConfig['migration_version'];

            return;
        }

        $registry = new MigrationRegistry($this->logger, $this->translator, $this->fileSystem);
        $globalMigrations = $registry->discoverGlobalMigrations();
        $latestMigrationId = $this->getLatestMigrationId($globalMigrations);
        if ($latestMigrationId !== null) {
            $config['migration_version'] = $latestMigrationId;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function saveConfig(array $config): void
    {
        $configDir = $this->fileSystem->dirname($this->configPath);
        if (! $this->fileSystem->isDir($configDir)) {
            try {
                $this->fileSystem->mkdir($configDir, 0700, true);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException("Failed to create config directory: {$configDir}", 0, $e);
            }
        }

        $filteredConfig = array_filter($config, [$this, 'filterEmptyStrings']);
        $this->fileSystem->filePutContents($this->configPath, Yaml::dump($filteredConfig));
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.success'));
    }

    /**
     * Gets the latest migration ID from the list of migrations.
     *
     * @param array<\App\Migrations\MigrationInterface> $migrations
     * @return string|null The latest migration ID, or null if no migrations exist
     */
    protected function getLatestMigrationId(array $migrations): ?string
    {
        if (empty($migrations)) {
            return null;
        }

        $latestId = null;
        foreach ($migrations as $migration) {
            $migrationId = $migration->getId();
            if ($latestId === null || strcmp($migrationId, $latestId) > 0) {
                $latestId = $migrationId;
            }
        }

        return $latestId;
    }

    protected function promptForCompletion(): void
    {
        $shell = $this->detectShell();
        if ($shell === null) {
            return;
        }

        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.completion.title'));

        $choice = $this->logger->choice(
            $this->translator->trans('config.init.completion.prompt', ['shell' => $shell]),
            [
                $this->translator->trans('config.init.completion.yes'),
                $this->translator->trans('config.init.completion.no'),
            ],
            $this->translator->trans('config.init.completion.no')
        );

        if ($choice === $this->translator->trans('config.init.completion.yes')) {
            $command = $shell === 'bash'
                ? $this->translator->trans('config.init.completion.bash_command')
                : $this->translator->trans('config.init.completion.zsh_command');

            $shellrc = $shell === 'bash' ? 'bashrc' : 'zshrc';

            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.completion.success_message'));
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, '  <info>' . $command . '</info>');
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.completion.reload_instruction', ['shellrc' => $shellrc]));
        } else {
            $this->logger->text(Logger::VERBOSITY_VERBOSE, $this->translator->trans('config.init.completion.skipped'));
        }
    }

    protected function detectShell(): ?string
    {
        $shellEnv = getenv('SHELL');
        if ($shellEnv === false) {
            return null;
        }

        $shellName = basename($shellEnv);

        return match (strtolower($shellName)) {
            'bash' => 'bash',
            'zsh' => 'zsh',
            default => null,
        };
    }

    /**
     * Attempts to detect system locale from environment variables.
     * Checks LC_ALL first, then LANG, and extracts the language code.
     * Returns null if detection fails or language is not supported.
     *
     * @return string|null The detected language code (e.g., 'fr', 'es') or null if not detected/unsupported
     */
    protected function detectSystemLocale(): ?string
    {
        // Supported languages in stud-cli
        $supportedLanguages = ['en', 'fr', 'es', 'nl', 'ru', 'el', 'af', 'vi'];

        // Check LC_ALL first, then LANG
        $locale = getenv('LC_ALL') ?: getenv('LANG');
        if ($locale === false || $locale === '') {
            return null;
        }

        // Extract language code from locale string (e.g., "fr_FR.UTF-8" -> "fr", "es_ES" -> "es")
        // Locale format is typically: language[_territory][.codeset][@modifier]
        $parts = explode('.', $locale);
        $languagePart = $parts[0];
        $languageCode = explode('_', $languagePart)[0];
        $languageCode = strtolower($languageCode);

        // Validate against supported languages
        if (in_array($languageCode, $supportedLanguages, true)) {
            return $languageCode;
        }

        // Language not supported, return null to fallback to 'en'
        return null;
    }

    /**
     * Filters out empty strings from config array while preserving null values.
     *
     * This method is used as a callback for array_filter to remove empty strings
     * from the configuration while preserving null values (which indicate optional
     * fields that weren't set).
     *
     * @param mixed $value The value to check
     * @return bool True if the value should be kept (not an empty string), false otherwise
     */
    private function filterEmptyStrings($value): bool
    {
        return $value !== '';
    }
}
