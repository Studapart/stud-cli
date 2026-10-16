<?php

declare(strict_types=1);

namespace App\DTO;

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
}
