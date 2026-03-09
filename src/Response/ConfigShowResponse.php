<?php

declare(strict_types=1);

namespace App\Response;

final class ConfigShowResponse extends AbstractResponse
{
    /**
     * @param array<string, mixed> $globalConfig Redacted global configuration
     * @param array<string, mixed>|null $projectConfig Redacted project configuration, null when not in a git repo
     * @param 'global'|'project'|null $singleKeySection Section that provided the value when single-key mode is used
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly array $globalConfig,
        public readonly ?array $projectConfig,
        public readonly ?string $singleKey = null,
        public readonly mixed $singleKeyValue = null,
        public readonly ?string $singleKeySection = null
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed>|null $projectConfig
     */
    public static function success(array $globalConfig, ?array $projectConfig = null): self
    {
        return new self(true, null, $globalConfig, $projectConfig, null, null, null);
    }

    /**
     * Success response for a single key lookup. Value is the effective (project-over-global) value.
     *
     * @param 'global'|'project' $section Section where the effective value comes from
     */
    public static function successSingleKey(string $key, mixed $value, string $section): self
    {
        return new self(true, null, [], null, $key, $value, $section);
    }

    /**
     * @param array<string, mixed> $parameters Parameters for the error message translation (e.g. ['key' => $key])
     */
    public static function error(string $error, array $parameters = []): self
    {
        $r = new self(false, $error, [], null, null, null, null);
        $r->errorParameters = $parameters;

        return $r;
    }

    /** @var array<string, mixed> */
    private array $errorParameters = [];

    /**
     * @return array<string, mixed>
     */
    public function getErrorParameters(): array
    {
        return $this->errorParameters;
    }

    public function isSingleKey(): bool
    {
        return $this->singleKey !== null;
    }
}
