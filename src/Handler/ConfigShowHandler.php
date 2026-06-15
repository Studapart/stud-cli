<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\SecretKeyPolicy;
use App\DTO\ConfigFileReadResult;
use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
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

    public function handle(?string $key = null): ConfigShowResponse
    {
        if (! $this->fileSystem->fileExists($this->globalConfigPath)) {
            return ConfigShowResponse::error('config.show.no_config_found');
        }

        $globalRead = $this->loadGlobalConfig();
        $projectRead = $this->loadProjectConfig();
        $diagnostics = $this->buildReadDiagnostics($globalRead, $projectRead);

        if ($key !== null) {
            return $this->handleSingleKey($key, $globalRead->config, $projectRead, $diagnostics);
        }

        $redactedGlobal = SecretKeyPolicy::redact($globalRead->config);
        $redactedProject = $this->redactProjectConfig($projectRead);

        return ConfigShowResponse::success($redactedGlobal, $redactedProject, $diagnostics);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param list<ResponseMessage> $diagnostics
     */
    protected function handleSingleKey(
        string $key,
        array $globalConfig,
        ?ConfigFileReadResult $projectRead,
        array $diagnostics,
    ): ConfigShowResponse {
        $allowedKeys = SecretKeyPolicy::getAllowedKeysForConfigShow();
        if (! in_array($key, $allowedKeys, true)) {
            return ConfigShowResponse::error('config.show.key_not_allowed', ['%key%' => $key]);
        }

        $projectConfig = $projectRead !== null ? $projectRead->config : [];
        $effective = $projectConfig !== [] ? array_merge($globalConfig, $projectConfig) : $globalConfig;
        if (! array_key_exists($key, $effective)) {
            return ConfigShowResponse::error('config.show.key_not_found', ['%key%' => $key]);
        }

        $value = $effective[$key];
        $section = ($projectConfig !== [] && array_key_exists($key, $projectConfig)) ? 'project' : 'global';

        return ConfigShowResponse::successSingleKey($key, $value, $section, $diagnostics);
    }

    protected function loadGlobalConfig(): ConfigFileReadResult
    {
        try {
            return ConfigFileReadResult::readable($this->fileSystem->parseFile($this->globalConfigPath));
        } catch (\Throwable $e) {
            return ConfigFileReadResult::unreadable($e->getMessage());
        }
    }

    protected function loadProjectConfig(): ?ConfigFileReadResult
    {
        if ($this->gitRepository === null) {
            return null;
        }

        try {
            $result = $this->gitRepository->readProjectConfigResult();

            return $result->config === [] && ! $result->isUnreadable() ? null : $result;
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @return list<ResponseMessage>
     */
    protected function buildReadDiagnostics(ConfigFileReadResult $globalRead, ?ConfigFileReadResult $projectRead): array
    {
        $diagnostics = [];

        if ($globalRead->isUnreadable()) {
            $diagnostics[] = ResponseMessage::warning(
                MessageRef::key('config.show.global_config_unreadable'),
                $globalRead->readFailureMessage,
            );
        }

        if ($projectRead?->isUnreadable() === true) {
            $diagnostics[] = ResponseMessage::warning(
                MessageRef::key('config.show.project_config_unreadable'),
                $projectRead->readFailureMessage,
            );
        }

        return $diagnostics;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function redactProjectConfig(?ConfigFileReadResult $projectRead): ?array
    {
        if ($projectRead === null || $projectRead->config === []) {
            return null;
        }

        return SecretKeyPolicy::redact($projectRead->config);
    }
}
