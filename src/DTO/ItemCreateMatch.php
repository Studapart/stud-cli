<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The project key and tracker tag one create lookup matched.
 */
final class ItemCreateMatch
{
    public function __construct(
        public readonly string $key,
        public readonly string $provider,
    ) {
    }
}
