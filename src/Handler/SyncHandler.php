<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Response\CommandResponse;
use App\Service\GitBranchService;
use App\Service\GitRepository;

class SyncHandler implements GitRepositoryAware, ProjectBaseBranchAware
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly string $baseBranch,
        mixed $_translator,
    ) {
        unset($_translator);
    }

    /**
     * Rebases the current feature branch onto the latest base branch.
     *
     * Steps: pre-flight checks → fetch → resolve base → check ancestry → rebase (abort on conflict).
     */
    public function handle(): CommandResponse
    {
        $currentBranch = $this->gitRepository->getCurrentBranchName();

        if ($this->isOnBaseBranch($currentBranch)) {
            return CommandResponse::error(MessageRef::key('sync.error_on_base_branch'));
        }

        if (! $this->isWorkingDirectoryClean()) {
            return CommandResponse::error(MessageRef::key('sync.error_dirty_working'));
        }

        $this->gitRepository->fetch();

        $resolvedBase = $this->gitBranchService->resolveLatestBaseBranch($this->baseBranch);

        if ($this->gitRepository->isAncestor($resolvedBase, 'HEAD')) {
            return CommandResponse::success(
                data: ['branch' => $currentBranch, 'base' => $resolvedBase],
                messages: [ResponseMessage::notice(MessageRef::key('sync.already_up_to_date'))],
            );
        }

        return $this->attemptRebase($currentBranch, $resolvedBase);
    }

    /**
     * Determines whether the current branch is the configured base branch.
     */
    protected function isOnBaseBranch(string $currentBranch): bool
    {
        $bare = str_starts_with($this->baseBranch, 'origin/')
            ? substr($this->baseBranch, 7)
            : $this->baseBranch;

        return $currentBranch === $bare;
    }

    protected function isWorkingDirectoryClean(): bool
    {
        return empty($this->gitRepository->getPorcelainStatus());
    }

    /**
     * Runs the rebase and aborts automatically on conflict.
     */
    protected function attemptRebase(string $currentBranch, string $resolvedBase): CommandResponse
    {
        if ($this->gitRepository->tryRebase($resolvedBase)) {
            return CommandResponse::success(MessageRef::key('sync.success', [
                'branch' => $currentBranch,
                'base' => $resolvedBase,
            ]), ['branch' => $currentBranch, 'base' => $resolvedBase]);
        }

        $this->gitRepository->rebaseAbort();

        return CommandResponse::error(MessageRef::key('sync.error_conflicts', [
            'base' => $resolvedBase,
        ]), data: ['branch' => $currentBranch, 'base' => $resolvedBase]);
    }
}
