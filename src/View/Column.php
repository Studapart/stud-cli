<?php

declare(strict_types=1);

namespace App\View;

final class Column
{
    /**
     * @param callable(mixed, array<string, mixed>): string|null $formatter
     */
    public function __construct(
        public readonly string $property,
        public readonly string $translationKey,
        public readonly mixed $formatter = null,
        public readonly ?string $condition = null
    ) {
    }
}
