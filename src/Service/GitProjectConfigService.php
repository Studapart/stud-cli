<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ConfigFileReadResult;
use Symfony\Component\Process\Process;

class GitProjectConfigService
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
        private readonly FileSystem $fileSystem,
        private readonly GitRemoteUrlParser $remoteUrlParser,
    ) {
    }

    public function getProjectConfigPath(): string
    {
        $process = $this->runQuietly('git rev-parse --git-dir');
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Not in a git repository.');
        }
        $gitDir = trim($process->getOutput());

        return rtrim($gitDir, '/') . '/stud.config';
    }

    /**
     * @return array{projectKey?: string, transitionId?: int, baseBranch?: string, gitProvider?: string, githubToken?: string, gitlabToken?: string, gitlabInstanceUrl?: string, JIRA_DEFAULT_PROJECT?: string, CONFLUENCE_DEFAULT_SPACE?: string, migration_version?: string}
     */
    public function readProjectConfig(): array
    {
        return $this->readProjectConfigResult()->config;
    }

    public function readProjectConfigResult(): ConfigFileReadResult
    {
        $configPath = $this->getProjectConfigPath();

        if (! $this->fileSystem->fileExists($configPath)) {
            return ConfigFileReadResult::missing();
        }

        try {
            return ConfigFileReadResult::readable($this->fileSystem->parseFile($configPath));
        } catch (\Exception $e) {
            return ConfigFileReadResult::unreadable($e->getMessage());
        }
    }

    /**
     * @param array{projectKey?: string, transitionId?: int, baseBranch?: string, gitProvider?: string, githubToken?: string, gitlabToken?: string, gitlabInstanceUrl?: string, JIRA_DEFAULT_PROJECT?: string, CONFLUENCE_DEFAULT_SPACE?: string, migration_version?: string} $config
     */
    public function writeProjectConfig(array $config): void
    {
        $configPath = $this->getProjectConfigPath();
        $configDir = dirname($configPath);

        if (! $this->fileSystem->isDir($configDir)) {
            throw new \RuntimeException("Git directory not found: {$configDir}");
        }

        if ($this->fileSystem->fileExists($configPath)) {
            $existingConfig = $this->readProjectConfig();
            if (isset($existingConfig['migration_version'])) {
                $config['migration_version'] = $existingConfig['migration_version'];
            }
        }

        $this->fileSystem->backupFileIfExists($configPath);
        $this->fileSystem->dumpFile($configPath, $config);
    }

    public function getProjectKeyFromIssueKey(string $issueKey): string
    {
        if (preg_match('/^([A-Z]+)-\d+$/', strtoupper($issueKey), $matches)) {
            return $matches[1];
        }

        throw new \RuntimeException("Invalid Jira issue key format: {$issueKey}");
    }

    /**
     * @return string|null The provider type ('github' or 'gitlab'), or null if not configured and cannot be detected
     */
    public function getGitProvider(): ?string
    {
        $config = $this->readProjectConfig();
        $provider = $config['gitProvider'] ?? null;

        if ($provider !== null && in_array($provider, ['github', 'gitlab'], true)) {
            return $provider;
        }

        $parsed = $this->remoteUrlParser->parseRemote('origin');
        if (isset($parsed['provider'])) {
            return $parsed['provider'];
        }

        return null;
    }

    protected function runQuietly(string $command): Process
    {
        $process = $this->processFactory->create($command);
        $process->run();

        return $process;
    }
}
