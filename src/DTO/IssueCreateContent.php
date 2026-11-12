<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * User-supplied content of a work item to create: summary, optional description, and optional parent.
 */
final class IssueCreateContent
{
    public function __construct(
        public readonly string $summary,
        public readonly ?string $description = null,
        public readonly ?string $descriptionFormat = null,
        public readonly ?string $parentKey = null,
    ) {
    }
}
