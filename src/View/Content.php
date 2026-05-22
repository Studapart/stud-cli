<?php

declare(strict_types=1);

namespace App\View;

final class Content
{
    public function __construct(
        public readonly mixed $contentExtractor,
        public readonly ?string $formatter = null
    ) {
    }
}
