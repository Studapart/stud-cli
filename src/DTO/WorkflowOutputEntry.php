<?php

declare(strict_types=1);

namespace App\DTO;

final class WorkflowOutputEntry
{
    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     * @param array<string> $headers
     * @param array<array<string>> $rows
     * @param array<string> $elements
     * @param array<mixed> $definitionList
     */
    public function __construct(
        public readonly string $type,
        public readonly int $verbosity,
        public readonly MessageRef|string|array|null $message = null,
        public readonly ?string $technicalDetails = null,
        public readonly array $headers = [],
        public readonly array $rows = [],
        public readonly array $elements = [],
        public readonly array $definitionList = [],
        public readonly int $count = 1,
    ) {
    }
}
