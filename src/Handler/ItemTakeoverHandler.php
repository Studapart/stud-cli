<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemTakeoverHandler
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly ItemStartHandler $itemStartHandler,
        private readonly string $baseBranch,
        private readonly TranslationService $translator,
        /** @phpstan-ignore-next-line */
        private readonly array $jiraConfig,
        private readonly Logger $logger
    ) {
        // $jiraConfig is kept for potential future use (e.g., transition handling)
    }

    public function handle(SymfonyStyle $io, string $key): int
    {
        $key = strtoupper($key);
        $io->section($this->translator->trans('item.takeover.section', ['key' => $key]));

        // Step 1: Validate working directory
        if (! $this->checkWorkingDirectory($io)) {
            return 1;
        }

        // Step 2: Fetch issue from Jira
        try {
            $issue = $this->jiraService->getIssue($key);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('item.takeover.error_not_found', ['key' => $key]));

            return 1;
        }

        // Step 3: Assign issue to current user
        $this->assignIssueToCurrentUser($io, $key);

        // Step 4: Fetch from remote
        $io->text($this->translator->trans('item.takeover.fetching'));
        $this->gitRepository->fetch();

        // Step 5: Search for branches
        $io->text($this->translator->trans('item.takeover.searching_branches'));
        $branches = $this->gitRepository->findBranchesByIssueKey($key);

        // Step 6: Handle branches
        if (empty($branches['local']) && empty($branches['remote'])) {
            return $this->handleNoBranches($io, $key);
        }

        return $this->handleExistingBranches($io, $key, $branches);
    }

    protected function checkWorkingDirectory(SymfonyStyle $io): bool
    {
        $status = $this->gitRepository->getPorcelainStatus();
        if (! empty(trim($status))) {
            $io->error($this->translator->trans('item.takeover.error_dirty_working'));

            return false;
        }

        return true;
    }

    protected function assignIssueToCurrentUser(SymfonyStyle $io, string $key): void
    {
        try {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('item.takeover.assigning', ['key' => $key])}");
            $this->jiraService->assignIssue($key);
        } catch (\Exception $e) {
            $io->warning($this->translator->trans('item.takeover.assign_warning', ['error' => $e->getMessage()]));
        }
    }

    protected function handleNoBranches(SymfonyStyle $io, string $key): int
    {
        $io->text($this->translator->trans('item.takeover.no_branches', ['key' => $key]));

        $startFresh = $io->confirm(
            $this->translator->trans('item.takeover.start_fresh_prompt'),
            true
        );

        if ($startFresh) {
            return $this->itemStartHandler->handle($io, $key);
        }

        return 0;
    }

    /**
     * @param array{local: array<string>, remote: array<string>} $branches
     */
    protected function handleExistingBranches(SymfonyStyle $io, string $key, array $branches): int
    {
        $selectedBranch = $this->selectBranch($io, $branches);

        if ($selectedBranch === null) {
            return 0;
        }

        $currentBranch = $this->gitRepository->getCurrentBranchName();

        // Check if already on target branch
        if ($currentBranch === $selectedBranch) {
            $io->text($this->translator->trans('item.takeover.already_on_branch', ['branch' => $selectedBranch]));
        } else {
            $this->switchToBranch($io, $selectedBranch, $branches);
        }

        // Get branch status
        $remoteBranch = $this->getRemoteBranchName($selectedBranch, $branches);
        $status = $this->gitRepository->getBranchStatus($selectedBranch, $this->baseBranch, $remoteBranch);

        // Check if branch is based on correct base
        $isBasedOnCorrectBase = $this->gitRepository->isBranchBasedOn($selectedBranch, $this->baseBranch);
        if (! $isBasedOnCorrectBase) {
            $io->warning($this->translator->trans('item.takeover.warning_wrong_base', ['base' => $this->baseBranch]));
        }

        // Handle branch synchronization
        $this->handleBranchSynchronization($io, $selectedBranch, $status, $remoteBranch);

        // Show success message
        $this->showSuccessMessage($io, $key, $selectedBranch, $status, $this->baseBranch);

        return 0;
    }

    /**
     * @param array{local: array<string>, remote: array<string>} $branches
     */
    protected function selectBranch(SymfonyStyle $io, array $branches): ?string
    {
        $allBranches = $this->combineBranches($branches);

        if (count($allBranches) === 1) {
            return $this->handleSingleBranch($io, $allBranches[0]);
        }

        return $this->handleMultipleBranches($io, $allBranches);
    }

    /**
     * @param array{name: string, is_remote: bool} $branch
     */
    protected function handleSingleBranch(SymfonyStyle $io, array $branch): ?string
    {
        if ($branch['is_remote']) {
            $confirmed = $io->confirm(
                $this->translator->trans('item.takeover.confirm_branch', ['branch' => $branch['name']]),
                true
            );

            return $confirmed ? $branch['name'] : null;
        }

        return $branch['name'];
    }

    /**
     * @param array<int, array{name: string, is_remote: bool}> $allBranches
     */
    protected function handleMultipleBranches(SymfonyStyle $io, array $allBranches): ?string
    {
        $io->text($this->translator->trans('item.takeover.branches_found', ['count' => count($allBranches)]));

        $options = $this->buildBranchOptions($allBranches);

        $io->text($this->translator->trans('item.takeover.select_branch'));
        $selected = $io->choice('', $options);

        return $this->extractBranchNameFromSelection($allBranches, $selected);
    }

    /**
     * @param array<int, array{name: string, is_remote: bool}> $allBranches
     * @return array<string>
     */
    protected function buildBranchOptions(array $allBranches): array
    {
        $options = [];
        foreach ($allBranches as $branch) {
            $label = $branch['is_remote']
                ? $this->translator->trans('item.takeover.branch_remote', ['branch' => $branch['name']])
                : $this->translator->trans('item.takeover.branch_local', ['branch' => $branch['name']]);
            $options[] = $label;
        }

        return $options;
    }

    /**
     * @param array<int, array{name: string, is_remote: bool}> $allBranches
     */
    protected function extractBranchNameFromSelection(array $allBranches, string $selected): ?string
    {
        foreach ($allBranches as $branch) {
            $label = $branch['is_remote']
                ? $this->translator->trans('item.takeover.branch_remote', ['branch' => $branch['name']])
                : $this->translator->trans('item.takeover.branch_local', ['branch' => $branch['name']]);

            if ($label === $selected) {
                return $branch['name'];
            }
        }

        return null;
    }

    /**
     * @param array{local: array<string>, remote: array<string>} $branches
     * @return array<int, array{name: string, is_remote: bool}>
     */
    protected function combineBranches(array $branches): array
    {
        $combined = [];

        // Prioritize remote branches
        foreach ($branches['remote'] as $branch) {
            $combined[] = ['name' => $branch, 'is_remote' => true];
        }

        foreach ($branches['local'] as $branch) {
            // Skip if already in combined (remote takes priority)
            if (! in_array($branch, array_column($combined, 'name'), true)) {
                $combined[] = ['name' => $branch, 'is_remote' => false];
            }
        }

        return $combined;
    }

    /**
     * @param array{local: array<string>, remote: array<string>} $branches
     */
    protected function switchToBranch(SymfonyStyle $io, string $branchName, array $branches): void
    {
        $io->text($this->translator->trans('item.takeover.switching', ['branch' => $branchName]));

        if (in_array($branchName, $branches['local'], true)) {
            $this->gitRepository->switchBranch($branchName);
        } elseif (in_array($branchName, $branches['remote'], true)) {
            $this->gitRepository->switchToRemoteBranch($branchName);
        }
    }

    /**
     * @param array{local: array<string>, remote: array<string>} $branches
     */
    protected function getRemoteBranchName(string $branchName, array $branches): ?string
    {
        if (in_array($branchName, $branches['remote'], true)) {
            return "origin/{$branchName}";
        }

        return null;
    }

    /**
     * @param array{behind_remote: int, ahead_remote: int, behind_base: int, ahead_base: int} $status
     */
    protected function handleBranchSynchronization(SymfonyStyle $io, string $branchName, array $status, ?string $remoteBranch): void
    {
        if ($remoteBranch === null) {
            return;
        }

        if ($status['behind_remote'] > 0) {
            $hasLocalCommits = $status['ahead_remote'] > 0;

            if ($hasLocalCommits) {
                $io->warning($this->translator->trans('item.takeover.warning_diverged'));
            } else {
                $io->text($this->translator->trans('item.takeover.pulling'));
                $this->gitRepository->pullWithRebase('origin', $branchName);
            }
        }
    }

    /**
     * @param array{behind_remote: int, ahead_remote: int, behind_base: int, ahead_base: int} $status
     */
    protected function showSuccessMessage(SymfonyStyle $io, string $key, string $branchName, array $status, string $baseBranch): void
    {
        $io->success($this->translator->trans('item.takeover.success_took_over', ['key' => $key]));
        $io->text($this->translator->trans('item.takeover.success_on_branch', ['branch' => $branchName]));

        $io->text($this->translator->trans('item.takeover.success_status_header'));

        $remoteStatus = $this->formatRemoteStatus($status);
        $io->text($this->translator->trans('item.takeover.success_status_remote', ['status' => $remoteStatus]));

        $baseStatus = $this->formatBaseStatus($status, $baseBranch);
        $io->text($this->translator->trans('item.takeover.success_status_base', ['base' => $baseBranch, 'status' => $baseStatus]));

        $io->note($this->translator->trans('item.takeover.success_view_details', ['key' => $key]));
    }

    /**
     * @param array{behind_remote: int, ahead_remote: int, behind_base: int, ahead_base: int} $status
     */
    protected function formatRemoteStatus(array $status): string
    {
        if ($status['behind_remote'] > 0) {
            return $this->translator->trans('item.takeover.status_behind_remote', ['count' => $status['behind_remote']]);
        }

        if ($status['ahead_remote'] > 0) {
            return $this->translator->trans('item.takeover.status_ahead_remote', ['count' => $status['ahead_remote']]);
        }

        return $this->translator->trans('item.takeover.status_sync_remote');
    }

    /**
     * @param array{behind_remote: int, ahead_remote: int, behind_base: int, ahead_base: int} $status
     */
    protected function formatBaseStatus(array $status, string $baseBranch): string
    {
        if ($status['behind_base'] > 0) {
            return $this->translator->trans('item.takeover.status_behind_base', ['count' => $status['behind_base'], 'base' => $baseBranch]);
        }

        if ($status['ahead_base'] > 0) {
            return $this->translator->trans('item.takeover.status_ahead_base', ['count' => $status['ahead_base'], 'base' => $baseBranch]);
        }

        return $this->translator->trans('item.takeover.status_sync_base', ['base' => $baseBranch]);
    }
}
