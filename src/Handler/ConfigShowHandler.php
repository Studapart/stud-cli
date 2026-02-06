<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\SecretKeyPolicy;
use App\Response\ConfigShowResponse;
use App\Service\FileSystem;
use App\Service\GitRepository;

class ConfigShowHandler
{
    public function __construct(
        private readonly FileSystem $fileSystem,
        private readonly string $globalConfigPath,
        private readonly ?GitRepository $gitRepository = null
    ) {
    }

    public function handle(): ConfigShowResponse
    {
        if (! $this->fileSystem->fileExists($this->globalConfigPath)) {
            return ConfigShowResponse::error('config.show.no_config_found');
        }

        $globalConfig = $this->loadGlobalConfig();
        $projectConfig = $this->loadProjectConfig();

        $redactedGlobal = SecretKeyPolicy::redact($globalConfig);
        $redactedProject = $projectConfig !== null ? SecretKeyPolicy::redact($projectConfig) : null;

        return ConfigShowResponse::success($redactedGlobal, $redactedProject);
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadGlobalConfig(): array
    {
        try {
            return $this->fileSystem->parseFile($this->globalConfigPath);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null null when not in a git repository or project config missing
     */
    protected function loadProjectConfig(): ?array
    {
        if ($this->gitRepository === null) {
            return null;
        }

        try {
            $config = $this->gitRepository->readProjectConfig();

            return $config === [] ? null : $config;
        } catch (\RuntimeException) {
            return null;
        }
    }
}
