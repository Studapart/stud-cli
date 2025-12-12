<?php

declare(strict_types=1);

namespace App\View;

final class Section
{
    /**
     * @param array<DefinitionItem|Content> $items
     */
    public function __construct(
        public readonly string $title,
        public readonly array $items
    ) {
    }
}
