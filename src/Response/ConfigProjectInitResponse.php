<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;

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
        MessageRef|string|null $error,
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
     * @param array<string, mixed> $parameters Translation parameters for the error key
     */
    public static function error(string $errorKey, array $parameters = []): self
    {
        return new self(false, MessageRef::key($errorKey, $parameters), false, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrorParameters(): array
    {
        $error = $this->getErrorMessage();

        return $error instanceof MessageRef ? $error->parameters : [];
    }
}
