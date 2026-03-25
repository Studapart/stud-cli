<?php

declare(strict_types=1);

namespace App\Response;

/**
 * Result of config:project-init: merged project config (redacted) and whether the file changed.
 */
final class ConfigProjectInitResponse extends AbstractResponse
{
    /**
     * @param array<string, mixed> $redactedProjectConfig Full project config after merge, secrets redacted
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly bool $updated,
        public readonly array $redactedProjectConfig,
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param array<string, mixed> $redactedProjectConfig
     */
    public static function success(bool $updated, array $redactedProjectConfig): self
    {
        return new self(true, null, $updated, $redactedProjectConfig);
    }

    /**
     * @param array<string, mixed> $parameters Optional translation parameters for the error key
     */
    public static function error(string $errorKey, array $parameters = []): self
    {
        $r = new self(false, $errorKey, false, []);
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
}
