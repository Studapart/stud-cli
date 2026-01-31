<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\FileSystem;
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
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io): void
    {
        $existingConfig = $this->fileSystem->fileExists($this->configPath) ? $this->fileSystem->parseFile($this->configPath) : [];

        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.wizard.title'));
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.wizard.description', ['path' => $this->configPath]));

        // Language Configuration
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.language.title'));
        $availableLanguages = ['en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'nl' => 'Dutch', 'ru' => 'Russian', 'el' => 'Greek', 'af' => 'Afrikaans', 'vi' => 'Vietnamese'];
        $defaultLanguage = $existingConfig['LANGUAGE'] ?? $this->detectSystemLocale() ?? 'en';

        // Create display options with format "English (en)"
        $languageOptions = [];
        $languageMap = []; // Maps display string to code
        foreach ($availableLanguages as $code => $name) {
            $display = "{$name} ({$code})";
            $languageOptions[] = $display;
            $languageMap[$display] = $code;
        }

        // Find the default display option
        $defaultDisplay = $availableLanguages[$defaultLanguage] . ' (' . $defaultLanguage . ')';

        $languageChoiceDisplay = $this->logger->choice(
            $this->translator->trans('config.init.language.prompt'),
            $languageOptions,
            $defaultDisplay
        );

        // Map back to the short code
        $languageChoice = $languageMap[$languageChoiceDisplay];

        // Jira Configuration
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.jira.title'));
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.jira.token_help'));
        $jiraUrl = $this->logger->ask($this->translator->trans('config.init.jira.url_prompt'), $existingConfig['JIRA_URL'] ?? null);
        $jiraEmail = $this->logger->ask($this->translator->trans('config.init.jira.email_prompt'), $existingConfig['JIRA_EMAIL'] ?? null);
        $jiraToken = $this->logger->askHidden($this->translator->trans('config.init.jira.token_prompt'));

        // Git Provider Configuration
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.git.title'));
        $this->logger->text(Logger::VERBOSITY_NORMAL, [
            $this->translator->trans('config.init.git.description'),
            $this->translator->trans('config.init.git.token_help'),
            $this->translator->trans('config.init.git.multiple_tokens_note'),
        ]);
        $githubToken = $this->logger->askHidden($this->translator->trans('config.init.git.github_token_prompt'));
        $gitlabToken = $this->logger->askHidden($this->translator->trans('config.init.git.gitlab_token_prompt'));

        // Jira Transition Configuration
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.jira_transition.title'));
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.jira_transition.description'));
        $jiraTransitionEnabled = $this->logger->confirm(
            $this->translator->trans('config.init.jira_transition.prompt'),
            $existingConfig['JIRA_TRANSITION_ENABLED'] ?? false
        );

        $config = [
            'LANGUAGE' => $languageChoice,
            'JIRA_URL' => rtrim($jiraUrl, '/'),
            'JIRA_EMAIL' => $jiraEmail,
            'JIRA_API_TOKEN' => $jiraToken ?: ($existingConfig['JIRA_API_TOKEN'] ?? null),
            'GITHUB_TOKEN' => $githubToken ?: ($existingConfig['GITHUB_TOKEN'] ?? null),
            'GITLAB_TOKEN' => $gitlabToken ?: ($existingConfig['GITLAB_TOKEN'] ?? null),
            'JIRA_TRANSITION_ENABLED' => $jiraTransitionEnabled,
        ];

        // Set initial migration version to latest migration ID
        $registry = new MigrationRegistry($this->logger, $this->translator);
        $globalMigrations = $registry->discoverGlobalMigrations();
        $latestMigrationId = $this->getLatestMigrationId($globalMigrations);
        if ($latestMigrationId !== null) {
            $config['migration_version'] = $latestMigrationId;
        }

        $configDir = $this->fileSystem->dirname($this->configPath);
        if (! $this->fileSystem->isDir($configDir)) {
            $this->fileSystem->mkdir($configDir, 0700, true);
        }

        $this->fileSystem->filePutContents($this->configPath, Yaml::dump(array_filter($config)));
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('config.init.success'));

        // Shell completion setup
        $this->promptForCompletion();
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
}
