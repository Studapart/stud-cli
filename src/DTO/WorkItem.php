<?php

declare(strict_types=1);

namespace App\DTO;

class WorkItem
{
    public function __construct(
        public string $key,
        public string $title,
        public string $status,
        public ?string $assignee,
        public string $description,
        public array $labels,
        public string $issueType,
        public array $components,
    ) {}
}
