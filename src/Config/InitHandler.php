<?php

namespace App\Config;

use App\FileSystem\FileSystem;
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
        $existingConfig = $this->fileSystem->fileExists($this->configPath) ? Yaml::parseFile($this->configPath) : [];

        $io->title('Stud CLI Configuration Wizard');
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
        ]);
        $gitProvider = $io->choice('Select your Git provider', ['github', 'gitlab'], $existingConfig['GIT_PROVIDER'] ?? 'github');
        $gitRepoOwner = $io->ask('Enter the repository owner/organization', $existingConfig['GIT_REPO_OWNER'] ?? null);
        $gitRepoName = $io->ask('Enter the repository name', $existingConfig['GIT_REPO_NAME'] ?? null);
        $gitToken = $io->askHidden('Enter your Git provider PAT (leave blank to keep existing)');

        $config = [
            'JIRA_URL' => rtrim($jiraUrl, '/'),
            'JIRA_EMAIL' => $jiraEmail,
            'JIRA_API_TOKEN' => $jiraToken ?: ($existingConfig['JIRA_API_TOKEN'] ?? null),
            'GIT_PROVIDER' => $gitProvider,
            'GIT_REPO_OWNER' => $gitRepoOwner,
            'GIT_REPO_NAME' => $gitRepoName,
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
