<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Parsed global config together with the path it was read from.
 */
final class GlobalConfigFile
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly array $config,
        public readonly string $path,
    ) {
    }
}
