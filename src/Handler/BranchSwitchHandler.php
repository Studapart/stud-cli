<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Guard\Capability\GitRepositoryAware;
use App\Response\BranchSwitchResponse;
use App\Service\GitBranchService;
use App\Service\GitRepository;

class BranchSwitchHandler implements GitRepositoryAware
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        mixed $_translator
    ) {
        unset($_translator);
    }

    /**
     * Switches to the branch matching the work-item key (Jira or Linear), or asks the caller to select one.
     */
    public function handle(string $key, bool $quiet = false, ?string $selectedBranch = null): BranchSwitchResponse
    {
        $normalizedKey = strtoupper(trim($key));
        if ($normalizedKey === '') {
            return BranchSwitchResponse::error('', MessageRef::key('branch.switch.error_no_key'));
        }

        if (! $this->isWorkingDirectoryClean()) {
            return BranchSwitchResponse::error($normalizedKey, MessageRef::key('branch.switch.error_dirty_working'));
        }

        $matches = $this->gitBranchService->findLocalBranchesContainingIssueKey($normalizedKey);
        if ($matches === []) {
            return BranchSwitchResponse::error($normalizedKey, MessageRef::key('branch.switch.error_no_branch', ['key' => $normalizedKey]));
        }

        if ($selectedBranch === null && $quiet && count($matches) > 1) {
            return BranchSwitchResponse::error($normalizedKey, MessageRef::key('branch.switch.error_multiple_branches', [
                'key' => $normalizedKey,
                'branches' => implode("\n", array_map(fn (string $branch): string => "- {$branch}", $matches)),
            ]), $matches);
        }

        $branch = $this->resolveBranch($matches, $selectedBranch);
        if ($branch === null) {
            return BranchSwitchResponse::needsSelection($normalizedKey, $matches);
        }

        try {
            $this->gitBranchService->switchBranch($branch);
        } catch (\RuntimeException $e) {
            return BranchSwitchResponse::error($normalizedKey, $e->getMessage(), $matches, $branch);
        }

        return BranchSwitchResponse::switched($normalizedKey, $branch);
    }

    /**
     * Determines whether local changes make switching unsafe.
     */
    protected function isWorkingDirectoryClean(): bool
    {
        return trim($this->gitRepository->getPorcelainStatus()) === '';
    }

    /**
     * @param array<string> $matches Matching local branches
     */
    protected function resolveBranch(array $matches, ?string $selectedBranch): ?string
    {
        if ($selectedBranch !== null) {
            return in_array($selectedBranch, $matches, true) ? $selectedBranch : null;
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        return null;
    }
}
