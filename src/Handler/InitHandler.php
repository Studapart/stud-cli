<?php

namespace App\Handler;

use App\Service\FileSystem;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

class InitHandler
{
    public function __construct(
        private readonly FileSystem $fileSystem,
        private readonly string $configPath
    ) {
    }

    public function handle(SymfonyStyle $io): void
    {
        $existingConfig = $this->fileSystem->fileExists($this->configPath) ? $this->fileSystem->parseFile($this->configPath) : [];

        $io->section('Stud CLI Configuration Wizard');
        $io->text('This will create or update your configuration file at: ' . $this->configPath);

        // Jira Configuration
        $io->section('Jira Configuration');
        $io->text('You can generate an API token here: https://id.atlassian.com/manage-profile/security/api-tokens');
        $jiraUrl = $io->ask('Enter your Jira URL', $existingConfig['JIRA_URL'] ?? null);
        $jiraEmail = $io->ask('Enter your Jira email address', $existingConfig['JIRA_EMAIL'] ?? null);
        $jiraToken = $io->askHidden('Enter your Jira API token (leave blank to keep existing)');

        // Git Provider Configuration
        $io->section('Git Provider Configuration');
        $io->text([
            'This is required for the `stud submit` command to create Pull Requests.',
            'You can generate a token here: https://github.com/settings/tokens', // Assuming GitHub
            'Note: Repository owner and name will be automatically detected from your git remote.',
        ]);
        $gitProvider = $io->choice('Select your Git provider', ['github', 'gitlab'], $existingConfig['GIT_PROVIDER'] ?? 'github');
        $gitToken = $io->askHidden('Enter your Git provider PAT (leave blank to keep existing)');

        $config = [
            'JIRA_URL' => rtrim($jiraUrl, '/'),
            'JIRA_EMAIL' => $jiraEmail,
            'JIRA_API_TOKEN' => $jiraToken ?: ($existingConfig['JIRA_API_TOKEN'] ?? null),
            'GIT_PROVIDER' => $gitProvider,
            'GIT_TOKEN' => $gitToken ?: ($existingConfig['GIT_TOKEN'] ?? null),
        ];

        $configDir = $this->fileSystem->dirname($this->configPath);
        if (!$this->fileSystem->isDir($configDir)) {
            $this->fileSystem->mkdir($configDir, 0700, true);
        }

        $this->fileSystem->filePutContents($this->configPath, Yaml::dump(array_filter($config)));
        $io->success('Configuration saved successfully!');
    }
}
