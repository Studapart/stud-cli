<?php

declare(strict_types=1);

namespace App\Service;

class GitBaseBranchResolver
{
    public function __construct(
        private readonly GitRepository $gitRepository
    ) {
    }

    /**
     * Resolves the most advanced ref between local and remote counterparts of a base branch.
     *
     * Given a configured baseBranch (e.g. "origin/develop" or "develop"), derives both
     * the local and remote tracking refs, checks which exist, and returns whichever
     * is more advanced (has more recent commits). If branches have diverged, prefers remote.
     *
     * @param string $baseBranch The configured base branch (may or may not have "origin/" prefix)
     * @return string The ref to use as the actual starting point for new branches
     */
    public function resolveLatestBaseBranch(string $baseBranch): string
    {
        [$localRef, $remoteRef] = $this->deriveLocalAndRemoteRefs($baseBranch);

        $localExists = $this->refExists($localRef);
        $remoteExists = $this->refExists($remoteRef);

        if ($localExists && ! $remoteExists) {
            return $localRef;
        }
        if (! $localExists) {
            return $remoteExists ? $remoteRef : $baseBranch;
        }

        return $this->pickMoreAdvancedRef($localRef, $remoteRef, $baseBranch);
    }

    /**
     * Derives local and remote ref names from a configured base branch.
     *
     * @return array{0: string, 1: string} [localRef, remoteRef]
     */
    protected function deriveLocalAndRemoteRefs(string $baseBranch): array
    {
        if (str_starts_with($baseBranch, 'origin/')) {
            return [substr($baseBranch, 7), $baseBranch];
        }

        return [$baseBranch, 'origin/' . $baseBranch];
    }

    /**
     * Checks whether a git ref (branch, tag, remote tracking ref) exists locally.
     */
    protected function refExists(string $ref): bool
    {
        return $this->gitRepository->runQuietly(['git', 'rev-parse', '--verify', '--quiet', $ref])->isSuccessful();
    }

    /**
     * Compares two existing refs and returns whichever is more advanced.
     * If diverged, prefers remote. If identical, returns the original configured value.
     */
    protected function pickMoreAdvancedRef(string $localRef, string $remoteRef, string $baseBranch): string
    {
        $localIsAncestor = $this->gitRepository->runQuietly(['git', 'merge-base', '--is-ancestor', $localRef, $remoteRef])->isSuccessful();
        $remoteIsAncestor = $this->gitRepository->runQuietly(['git', 'merge-base', '--is-ancestor', $remoteRef, $localRef])->isSuccessful();

        if ($localIsAncestor && $remoteIsAncestor) {
            return $baseBranch;
        }
        if ($localIsAncestor) {
            return $remoteRef;
        }

        return $remoteIsAncestor ? $localRef : $remoteRef;
    }
}
