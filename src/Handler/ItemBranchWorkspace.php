<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Enum\WorkflowChannel;
use App\Service\GitBranchService;
use App\Service\GitRepository;

final class ItemBranchWorkspace
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly string $baseBranch,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function readProjectConfig(): array
    {
        return $this->gitRepository->readProjectConfig();
    }

    public function fetch(): void
    {
        $this->gitRepository->fetch();
    }

    public function porcelainStatus(): string
    {
        return $this->gitRepository->getPorcelainStatus();
    }

    public function currentBranchName(): string
    {
        return $this->gitRepository->getCurrentBranchName();
    }

    public function baseBranch(): string
    {
        return $this->baseBranch;
    }

    /**
     * @return array{local: array<string>, remote: array<string>}
     */
    public function findBranches(string $key): array
    {
        return $this->gitBranchService->findBranchesByIssueKey($key);
    }

    public function switchLocal(string $branchName): void
    {
        $this->gitBranchService->switchBranch($branchName);
    }

    public function switchRemote(string $branchName): void
    {
        $this->gitBranchService->switchToRemoteBranch($branchName);
    }

    /**
     * @return array{behind_remote: int, ahead_remote: int, behind_base: int, ahead_base: int}
     */
    public function branchStatus(string $branch, ?string $remoteBranch): array
    {
        return $this->gitBranchService->getBranchStatus($branch, $this->baseBranch, $remoteBranch);
    }

    public function isBasedOnConfiguredBase(string $branch): bool
    {
        return $this->gitBranchService->isBranchBasedOn($branch, $this->baseBranch);
    }

    public function pullWithRebase(string $branchName): void
    {
        $this->gitRepository->pullWithRebase('origin', $branchName);
    }

    /**
     * @param array{action: string, branch: string} $branchAction
     */
    public function applyStartAction(WorkflowEntryRecorder $recorder, array $branchAction, string $defaultBranchName): void
    {
        if ($branchAction['action'] === BranchAction::SWITCH_LOCAL) {
            $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.switching_branch', ['branch' => $branchAction['branch']]), WorkflowChannel::Git);
            $this->gitBranchService->switchBranch($branchAction['branch']);
            $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.success_switched', ['branch' => $branchAction['branch']]));

            return;
        }

        if ($branchAction['action'] === BranchAction::SWITCH_REMOTE) {
            $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.switching_remote_branch', ['branch' => $branchAction['branch']]), WorkflowChannel::Git);
            $this->gitBranchService->switchToRemoteBranch($branchAction['branch']);
            $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.success_switched', ['branch' => $branchAction['branch']]));

            return;
        }

        $resolvedBase = $this->gitBranchService->resolveLatestBaseBranch($this->baseBranch);
        if ($resolvedBase !== $this->baseBranch) {
            $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.using_advanced_base', ['configured' => $this->baseBranch, 'resolved' => $resolvedBase]), WorkflowChannel::Git);
        }
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.creating_branch', ['branch' => $defaultBranchName]), WorkflowChannel::Git);
        $this->gitRepository->createBranch($defaultBranchName, $resolvedBase);
        $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.success', ['branch' => $defaultBranchName, 'base' => $resolvedBase]));
    }
}
