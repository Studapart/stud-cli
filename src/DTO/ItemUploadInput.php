<?php

declare(strict_types=1);

namespace App\DTO;

final class ItemUploadInput
{
    /**
     * @param list<string> $filePaths Paths relative to cwd (validated in handler)
     */
    public function __construct(
        public readonly string $issueKey,
        public readonly array $filePaths,
    ) {
    }
}
