<?php

declare(strict_types=1);

namespace App\Service;

final class BranchRenameGit
{
    public function __construct(
        public readonly GitRepository $repository,
        public readonly GitBranchService $branches,
    ) {
    }
}
