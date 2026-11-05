<?php

declare(strict_types=1);

namespace App\DTO;

use App\Response\ConfigValidateResponse;

/**
 * Result of a single config:validate integration ping.
 */
final class ConfigValidateIntegrationPing
{
    public function __construct(
        public readonly string $status,
        public readonly MessageRef|string|null $message = null,
    ) {
    }

    public static function ok(): self
    {
        return new self(ConfigValidateResponse::STATUS_OK);
    }

    public static function skipped(): self
    {
        return new self(ConfigValidateResponse::STATUS_SKIPPED);
    }

    public static function fail(MessageRef|string $message): self
    {
        return new self(ConfigValidateResponse::STATUS_FAIL, $message);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function failWithKey(string $key, array $parameters = []): self
    {
        return self::fail(MessageRef::key($key, $parameters));
    }
}
