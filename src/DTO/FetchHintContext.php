<?php

declare(strict_types=1);

namespace App\DTO;

final class FetchHintContext
{
    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function __construct(
        public readonly string $attemptedProvider,
        public readonly ?string $cliOverride,
        public readonly array $globalConfig,
        public readonly array $projectConfig,
    ) {
    }
}
