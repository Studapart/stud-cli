<?php

declare(strict_types=1);

namespace App\Service;

class GitBaseBranchResolver
{
    /**
     * Returns the remote-tracking ref for the configured base branch.
     *
     * Local develop is never selected, including when it contains commits that are not on origin.
     *
     * @param string $baseBranch Configured base, with or without an origin/ prefix
     */
    public function resolveLatestBaseBranch(string $baseBranch): string
    {
        if (str_starts_with($baseBranch, 'origin/')) {
            return $baseBranch;
        }

        return 'origin/' . $baseBranch;
    }
}
