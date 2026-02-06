<?php

declare(strict_types=1);

namespace App\Response;

final class ConfigShowResponse extends AbstractResponse
{
    /**
     * @param array<string, mixed> $globalConfig Redacted global configuration
     * @param array<string, mixed>|null $projectConfig Redacted project configuration, null when not in a git repo
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly array $globalConfig,
        public readonly ?array $projectConfig
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed>|null $projectConfig
     */
    public static function success(array $globalConfig, ?array $projectConfig = null): self
    {
        return new self(true, null, $globalConfig, $projectConfig);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, [], null);
    }
}
