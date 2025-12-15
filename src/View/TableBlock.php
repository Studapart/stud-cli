<?php

declare(strict_types=1);

namespace App\View;

/**
 * Represents a table content block within a PageViewConfig section.
 */
final class TableBlock
{
    /**
     * @param Column[] $columns
     */
    public function __construct(
        public readonly array $columns
    ) {
    }
}
