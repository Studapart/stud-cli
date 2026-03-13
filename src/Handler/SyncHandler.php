<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly string $baseBranch,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    /**
     * Rebases the current feature branch onto the latest base branch.
     *
     * Steps: pre-flight checks → fetch → resolve base → check ancestry → rebase (abort on conflict).
     */
    public function handle(SymfonyStyle $io): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('sync.section'));

        $currentBranch = $this->gitRepository->getCurrentBranchName();

        if ($this->isOnBaseBranch($currentBranch)) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('sync.error_on_base_branch'));

            return 1;
        }

        if (! $this->isWorkingDirectoryClean()) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('sync.error_dirty_working'));

            return 1;
        }

        $this->logger->text(Logger::VERBOSITY_VERBOSE, $this->translator->trans('sync.fetching'));
        $this->gitRepository->fetch();

        $resolvedBase = $this->gitBranchService->resolveLatestBaseBranch($this->baseBranch);

        if ($this->gitRepository->isAncestor($resolvedBase, 'HEAD')) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('sync.already_up_to_date'));

            return 0;
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
    protected function attemptRebase(string $currentBranch, string $resolvedBase): int
    {
        $this->logger->text(Logger::VERBOSITY_VERBOSE, $this->translator->trans('sync.rebasing', [
            'branch' => $currentBranch,
            'base' => $resolvedBase,
        ]));

        if ($this->gitRepository->tryRebase($resolvedBase)) {
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('sync.success', [
                'branch' => $currentBranch,
                'base' => $resolvedBase,
            ]));

            return 0;
        }

        $this->gitRepository->rebaseAbort();

        $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('sync.error_conflicts', [
            'base' => $resolvedBase,
        ]));

        return 1;
    }
}
