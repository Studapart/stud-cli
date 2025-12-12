<?php

declare(strict_types=1);

namespace App\View;

final class Content
{
    /**
     * @param callable(mixed, array<string, mixed>): string|array<string> $contentExtractor
     */
    public function __construct(
        public readonly mixed $contentExtractor,
        public readonly ?string $formatter = null
    ) {
    }
}
