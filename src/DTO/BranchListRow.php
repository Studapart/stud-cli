<?php

declare(strict_types=1);

namespace App\DTO;

final class BranchListRow
{
    public function __construct(
        public readonly string $branch,
        public readonly string $status,
        public readonly string $remote,
        public readonly string $pr
    ) {
    }
}
