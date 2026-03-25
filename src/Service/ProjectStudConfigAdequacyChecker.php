<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Decides whether project-level `.git/stud.config` is adequately set for typical stud workflows.
 *
 * A file that exists but only contains `migration_version` (or other keys without `baseBranch`)
 * is treated as incomplete so users are guided toward `stud config:project-init`.
 */
class ProjectStudConfigAdequacyChecker
{
    /**
     * Adequate when `baseBranch` is a non-empty string (required for branch-targeted commands).
     *
     * @param array<string, mixed> $projectConfig
     */
    public function isAdequate(array $projectConfig): bool
    {
        $branch = $projectConfig['baseBranch'] ?? null;
        if (! is_string($branch) || trim($branch) === '') {
            return false;
        }

        return true;
    }
}
