<?php

declare(strict_types=1);

namespace App\Service;

final class SubmitGit
{
    public function __construct(
        public readonly GitRepository $repository,
        public readonly string $baseBranch,
    ) {
    }
}
