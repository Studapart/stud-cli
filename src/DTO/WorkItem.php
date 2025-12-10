<?php

declare(strict_types=1);

namespace App\DTO;

class WorkItem
{
    /**
     * @param array<string> $labels
     * @param array<string> $components
     */
    public function __construct(
        public readonly string $id,
        public string $key,
        public string $title,
        public string $status,
        public ?string $assignee,
        public string $description,
        public array $labels,
        public string $issueType,
        public array $components = [],
        public ?string $priority = null,
        public ?string $renderedDescription = null,
    ) {
    }
}
