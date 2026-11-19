<?php

declare(strict_types=1);

namespace App\DTO;

final class BranchRenamePostActions
{
    /**
     * @param array<string, mixed>|null $pr
     */
    public function __construct(
        public readonly ?array $pr,
        public readonly string $targetBranch,
        public readonly string $newBranchName,
        public readonly bool $quiet,
    ) {
    }
}
