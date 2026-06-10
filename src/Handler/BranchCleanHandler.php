<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\BranchCleanupPlan;
use App\DTO\BranchDeletionEligibility;
use App\DTO\MessageRef;
use App\Enum\BranchAutoCleanDecision;
use App\Enum\BranchCleanupLocalAction;
use App\Enum\BranchCleanupRemoteAction;
use App\Service\BranchCleanupExecutor;
use App\Service\BranchDeletionEligibilityResolver;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\WorkflowOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchCleanHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly BranchDeletionEligibilityResolver $eligibilityResolver,
        private readonly BranchCleanupExecutor $cleanupExecutor,
        private readonly ?string $configuredBaseBranch,
        mixed $_translator,
        private readonly WorkflowOutput $logger
    ) {
        unset($_translator);
    }

    public function handle(SymfonyStyle $io, bool $quiet = false): int
    {
        $this->displayHeader();

        $baseBranch = $this->resolveBaseBranch($quiet);
        $result = $this->findBranchesToClean($baseBranch, $quiet);
        $cleanupPlans = $result['automatic'];
        $currentBranchSkipped = $result['current_branch_skipped'];
        $manualPlans = $result['manual'];

        if (! $quiet) {
            $this->addManuallyConfirmedPlans($manualPlans, $cleanupPlans, $quiet);
        }

        if ($this->shouldExitEarly($cleanupPlans, $currentBranchSkipped, $manualPlans)) {
            if ($quiet) {
                $this->displayManualBranchesReport($manualPlans);
            }

            return 0;
        }

        $this->notifyCurrentBranchSkipped($currentBranchSkipped);
        $this->displayBranchesToClean($cleanupPlans, $quiet);

        if (! $this->confirmDeletion($cleanupPlans, $quiet)) {
            return 0;
        }

        $deletedCount = $this->deleteBranches($cleanupPlans, $quiet);
        $this->displayDeletionResult($deletedCount);
        if ($quiet) {
            $this->displayManualBranchesReport($manualPlans);
        }

        return 0;
    }

    /**
     * Displays the header section.
     */
    protected function displayHeader(): void
    {
        $this->logger->addSection(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.section'));
        $this->logger->addNote(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.note_origin'));
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.scanning'));
        $this->logger->addLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.fetching_local'));
    }

    /**
     * Checks if we should exit early (no branches to clean).
     *
     * @param array<BranchCleanupPlan> $cleanupPlans Cleanup plans to execute
     * @param bool $currentBranchSkipped Whether current branch was skipped
     * @param array<BranchCleanupPlan> $manualPlans Manual cleanup plans
     * @return bool True if should exit early, false otherwise
     */
    protected function shouldExitEarly(array $cleanupPlans, bool $currentBranchSkipped, array $manualPlans): bool
    {
        $totalBranches = count($cleanupPlans);
        $hasManualBranches = ! empty($manualPlans);

        if ($totalBranches === 0 && ! $currentBranchSkipped && ! $hasManualBranches) {
            $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.none'));

            return true;
        }

        if ($totalBranches === 0 && ! $hasManualBranches) {
            return true;
        }

        return false;
    }

    /**
     * Notifies user if current branch was skipped.
     *
     * @param bool $currentBranchSkipped Whether current branch was skipped
     */
    protected function notifyCurrentBranchSkipped(bool $currentBranchSkipped): void
    {
        if ($currentBranchSkipped) {
            $currentBranch = $this->gitRepository->getCurrentBranchName();
            $this->logger->addNote(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.current_branch_skipped', ['branch' => $currentBranch]));
        }
    }

    /**
     * Displays the list of branches to be cleaned.
     *
     * @param array<BranchCleanupPlan> $cleanupPlans Cleanup plans to display
     * @param bool $quiet Whether in quiet mode
     */
    protected function displayBranchesToClean(array $cleanupPlans, bool $quiet): void
    {
        $branchesToCleanLocal = $this->getLocalOnlyBranchNames($cleanupPlans);
        $branchesToCleanRemote = $this->getRemoteBranchNames($cleanupPlans);
        $totalBranches = count($cleanupPlans);
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.found', ['count' => $totalBranches]));

        if (! empty($branchesToCleanLocal)) {
            $this->logger->addLine(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.local_only_header', ['count' => count($branchesToCleanLocal)]));
            $this->displayBranchesList($branchesToCleanLocal);
        }

        if (! empty($branchesToCleanRemote)) {
            $this->logger->addLine(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.with_remote_header', ['count' => count($branchesToCleanRemote)]));
            $this->displayBranchesList($branchesToCleanRemote);
            if (! $quiet) {
                $this->logger->addNote(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.with_remote_note'));
            }
        }
    }

    /**
     * Confirms deletion with user (if not in quiet mode).
     *
     * @param array<BranchCleanupPlan> $cleanupPlans Cleanup plans to execute
     * @param bool $quiet Whether in quiet mode
     * @return bool True if confirmed or quiet mode, false if cancelled
     */
    protected function confirmDeletion(array $cleanupPlans, bool $quiet): bool
    {
        if ($quiet) {
            return true;
        }

        $totalBranches = count($cleanupPlans);
        $confirmed = $this->logger->confirm(
            MessageRef::key('branches.clean.confirm', ['count' => $totalBranches]),
            true
        );

        if (! $confirmed) {
            $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.cancelled'));

            return false;
        }

        return true;
    }

    /**
     * Displays the deletion result.
     *
     * @param int $deletedCount Number of branches deleted
     */
    protected function displayDeletionResult(int $deletedCount): void
    {
        if ($deletedCount > 0) {
            $this->logger->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.deleted', ['count' => $deletedCount]));
        }
    }

    /**
     * @return array{
     *   automatic: array<BranchCleanupPlan>,
     *   current_branch_skipped: bool,
     *   manual: array<BranchCleanupPlan>
     * }
     */
    protected function findBranchesToClean(?string $baseBranch, bool $quiet): array
    {
        $allBranches = $this->fetchAllBranches();
        $remoteBranchesSet = $this->fetchRemoteBranchesSet();
        $currentBranch = $this->gitRepository->getCurrentBranchName();
        $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=gray>Current branch: {$currentBranch}</>");
        $prSnapshot = $this->eligibilityResolver->buildPullRequestSnapshot();

        $automaticPlans = [];
        $currentBranchSkipped = false;
        $manualPlans = [];

        foreach ($allBranches as $branch) {
            $remoteExists = isset($remoteBranchesSet[$branch]);
            $eligibility = $this->eligibilityResolver->evaluate(
                $branch,
                $currentBranch,
                $remoteExists,
                $baseBranch,
                $prSnapshot['map'],
                $prSnapshot['available']
            );

            if ($eligibility->reason === 'current_branch') {
                $currentBranchSkipped = true;
            }

            $plan = $this->buildCleanupPlan($branch, $eligibility, $remoteExists, $quiet);
            if ($plan->localAction === BranchCleanupLocalAction::Manual) {
                $manualPlans[] = $plan;
            }

            if (
                $plan->localAction === BranchCleanupLocalAction::SafeDelete
                || $plan->localAction === BranchCleanupLocalAction::ForceDelete
            ) {
                $automaticPlans[] = $plan;
            }
        }

        return [
            'automatic' => $automaticPlans,
            'current_branch_skipped' => $currentBranchSkipped,
            'manual' => $manualPlans,
        ];
    }

    protected function buildCleanupPlan(
        string $branch,
        BranchDeletionEligibility $eligibility,
        bool $remoteExists,
        bool $quiet,
    ): BranchCleanupPlan {
        if ($eligibility->decision === BranchAutoCleanDecision::Manual) {
            return new BranchCleanupPlan(
                $branch,
                $eligibility,
                $remoteExists,
                BranchCleanupLocalAction::Manual,
                BranchCleanupRemoteAction::Manual
            );
        }

        if ($eligibility->decision !== BranchAutoCleanDecision::Yes) {
            return new BranchCleanupPlan(
                $branch,
                $eligibility,
                $remoteExists,
                BranchCleanupLocalAction::Skip,
                BranchCleanupRemoteAction::Skip
            );
        }

        $localAction = $eligibility->mergedByGit
            ? BranchCleanupLocalAction::SafeDelete
            : BranchCleanupLocalAction::ForceDelete;

        return new BranchCleanupPlan(
            $branch,
            $eligibility,
            $remoteExists,
            $localAction,
            $this->resolveAutomaticRemoteAction($remoteExists, $quiet)
        );
    }

    protected function resolveAutomaticRemoteAction(bool $remoteExists, bool $quiet): BranchCleanupRemoteAction
    {
        if (! $remoteExists) {
            return BranchCleanupRemoteAction::Skip;
        }

        return $quiet ? BranchCleanupRemoteAction::KeepQuiet : BranchCleanupRemoteAction::PromptDelete;
    }

    /**
     * Fetches all local branches.
     *
     * @return array<string> All local branch names
     */
    protected function fetchAllBranches(): array
    {
        $this->logger->addLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.fetching_local'));
        $allBranches = $this->gitBranchService->getAllLocalBranches();
        $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=gray>Found " . count($allBranches) . " local branches</>");

        return $allBranches;
    }

    /**
     * Fetches remote branches as a set for fast lookup.
     *
     * @return array<string, int> Remote branch names as keys (flipped array)
     */
    protected function fetchRemoteBranchesSet(): array
    {
        $this->logger->addLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('branches.clean.fetching_remote'));
        $remoteBranches = $this->gitBranchService->getAllRemoteBranches('origin');
        $this->logger->addLine(WorkflowOutput::VERBOSITY_DEBUG, "    <fg=gray>Found " . count($remoteBranches) . " remote branches on origin</>");

        return array_flip($remoteBranches);
    }

    protected function resolveBaseBranch(bool $quiet): ?string
    {
        $resolved = $this->eligibilityResolver->resolveBaseBranch($this->configuredBaseBranch);
        if ($resolved !== null || $quiet) {
            return $resolved;
        }

        $enteredBranch = $this->logger->ask(
            MessageRef::key('branches.clean.base_branch_prompt'),
            'develop',
            function (?string $value): string {
                $branch = trim((string) $value);
                if ($branch === '') {
                    throw new \RuntimeException('Base branch cannot be empty.');
                }

                return $branch;
            }
        );
        if ($enteredBranch === null || ! $this->gitRepository->remoteBranchExists('origin', $enteredBranch)) {
            return null;
        }

        return 'origin/' . $enteredBranch;
    }

    /**
     * @param array<BranchCleanupPlan> $manualPlans
     * @param array<BranchCleanupPlan> $cleanupPlans
     */
    protected function addManuallyConfirmedPlans(array $manualPlans, array &$cleanupPlans, bool $quiet): void
    {
        foreach ($manualPlans as $manualPlan) {
            $confirm = $this->logger->confirm(
                MessageRef::key('branches.clean.manual_confirm', [
                    'branch' => $manualPlan->branch,
                    'reason' => $this->translateReason($manualPlan->eligibility->reason),
                ]),
                false
            );
            if (! $confirm) {
                continue;
            }

            $cleanupPlans[] = $this->buildManuallyConfirmedPlan($manualPlan, $quiet);
        }
    }

    /**
     * @param array<BranchCleanupPlan> $manualPlans
     */
    protected function displayManualBranchesReport(array $manualPlans): void
    {
        if ($manualPlans === []) {
            return;
        }

        $this->logger->addNote(
            WorkflowOutput::VERBOSITY_NORMAL,
            MessageRef::key('branches.clean.manual_report_header', ['count' => count($manualPlans)])
        );

        foreach ($manualPlans as $manualPlan) {
            $this->logger->addText(
                WorkflowOutput::VERBOSITY_NORMAL,
                MessageRef::key('branches.clean.manual_report_row', [
                    'branch' => $manualPlan->branch,
                    'reason' => $this->translateReason($manualPlan->eligibility->reason),
                ])
            );
        }

        $this->logger->addNote(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('branches.clean.manual_report_hint'));
    }

    protected function translateReason(string $reason): MessageRef
    {
        return MessageRef::key("branches.clean.reason.{$reason}");
    }

    protected function buildManuallyConfirmedPlan(BranchCleanupPlan $manualPlan, bool $quiet): BranchCleanupPlan
    {
        return new BranchCleanupPlan(
            $manualPlan->branch,
            $manualPlan->eligibility,
            $manualPlan->remoteExists,
            BranchCleanupLocalAction::SafeDelete,
            $this->resolveAutomaticRemoteAction($manualPlan->remoteExists, $quiet)
        );
    }

    /**
     * Displays the list of branches to be cleaned.
     *
     * @param array<string> $branches The branches to display
     */
    protected function displayBranchesList(array $branches): void
    {
        foreach ($branches as $branch) {
            $this->logger->addLine(WorkflowOutput::VERBOSITY_NORMAL, "  - {$branch}");
        }
    }

    /**
     * Deletes the specified branches, handling errors gracefully.
     *
     * @param array<BranchCleanupPlan> $cleanupPlans Plans to execute
     * @param bool $quiet Whether to skip remote deletion prompts
     * @return int Number of successfully deleted branches
     */
    protected function deleteBranches(array $cleanupPlans, bool $quiet): int
    {
        return $this->cleanupExecutor->execute($cleanupPlans, $quiet);
    }

    /**
     * @param array<BranchCleanupPlan> $cleanupPlans
     * @return array<string>
     */
    protected function getLocalOnlyBranchNames(array $cleanupPlans): array
    {
        return array_values(array_map(
            fn (BranchCleanupPlan $plan): string => $plan->branch,
            array_filter($cleanupPlans, fn (BranchCleanupPlan $plan): bool => ! $plan->remoteExists)
        ));
    }

    /**
     * @param array<BranchCleanupPlan> $cleanupPlans
     * @return array<string>
     */
    protected function getRemoteBranchNames(array $cleanupPlans): array
    {
        return array_values(array_map(
            fn (BranchCleanupPlan $plan): string => $plan->branch,
            array_filter($cleanupPlans, fn (BranchCleanupPlan $plan): bool => $plan->remoteExists)
        ));
    }
}
