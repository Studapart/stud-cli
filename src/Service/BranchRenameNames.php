<?php

declare(strict_types=1);

namespace App\Service;

final class BranchRenameNames
{
    public function __construct(
        public readonly BranchNameGenerator $generator,
        public readonly BranchNameValidator $validator,
    ) {
    }
}
