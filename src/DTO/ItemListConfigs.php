<?php

declare(strict_types=1);

namespace App\DTO;

final class ItemListConfigs
{
    /**
     * @param array<string, mixed> $global
     * @param array<string, mixed> $project
     */
    public function __construct(
        public readonly array $global,
        public readonly array $project,
    ) {
    }
}
