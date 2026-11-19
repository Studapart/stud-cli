<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads the submit-relevant facts of the current branch: base-branch guard, first logical commit, and issue key.
 */
class SubmitBranchInspector
{
    public function __construct(
        private readonly SubmitGit $git,
    ) {
    }

    public function isBaseBranch(string $branch): bool
    {
        $baseBranchName = str_replace('origin/', '', $this->git->baseBranch);

        return $branch === $baseBranchName || in_array($branch, ['main', 'master'], true);
    }

    public function firstLogicalCommitMessage(): ?string
    {
        $ancestorSha = $this->git->repository->getMergeBase($this->git->baseBranch, 'HEAD');
        $firstCommitSha = $this->git->repository->findFirstLogicalSha($ancestorSha);
        if ($firstCommitSha === null) {
            return null;
        }

        return $this->git->repository->getCommitMessage($firstCommitSha);
    }

    /**
     * Prefers the key in the branch name, then a bracketed key such as "[SCI-1]" in the commit message.
     */
    public function resolveIssueKey(string $commitMessage): ?string
    {
        $issueKey = $this->git->repository->getIssueKeyFromBranchName();
        if ($issueKey === null || $issueKey === '') {
            preg_match('/(?i)\[([a-z]+-\d+)]/', $commitMessage, $matches);
            $issueKey = $matches[1] ?? null;
        }

        return $issueKey;
    }
}
