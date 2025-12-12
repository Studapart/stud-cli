<?php

declare(strict_types=1);

namespace App\View;

final class DefinitionItem
{
    /**
     * @param callable(mixed, array<string, mixed>): string $valueExtractor
     */
    public function __construct(
        public readonly string $translationKey,
        public readonly mixed $valueExtractor
    ) {
    }
}
