<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GithubProvider;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\AsciiSlugger;

class BranchRenameHandler
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly ?GithubProvider $githubProvider,
        private readonly TranslationService $translator,
        private readonly array $jiraConfig,
        private readonly string $baseBranch,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io, ?string $branchName, ?string $key, ?string $explicitName): int
    {
        $io->section($this->translator->trans('branch.rename.section'));

        if (! $this->validateWorkingDirectory($io)) {
            return 1;
        }

        [$targetBranch, $key] = $this->normalizeBranchAndKey($branchName, $key);
        $newBranchName = $this->determineNewBranchName($io, $targetBranch, $key, $explicitName);
        if ($newBranchName === null || $this->validateNewBranchNameExists($io, $newBranchName)) {
            return 1;
        }

        $branchStatus = $this->checkBranchExistence($io, $targetBranch);
        if ($branchStatus === null || $branchStatus === false) {
            return $branchStatus === false ? 0 : 1;
        }

        [$hasLocal, $hasRemote] = $branchStatus;
        if ($this->handleBranchSynchronization($io, $targetBranch, $hasLocal, $hasRemote) === 1) {
            return 1;
        }

        return $this->performRename($io, $targetBranch, $newBranchName, $branchStatus);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    protected function normalizeBranchAndKey(?string $branchName, ?string $key): array
    {
        if ($branchName !== null && $key === null && $this->looksLikeJiraKey($branchName)) {
            return [$this->gitRepository->getCurrentBranchName(), strtoupper($branchName)];
        }

        $targetBranch = $branchName ?? $this->gitRepository->getCurrentBranchName();

        return [$targetBranch, $key];
    }

    /**
     * @param array{0: bool, 1: bool} $branchStatus
     */
    protected function performRename(SymfonyStyle $io, string $targetBranch, string $newBranchName, array $branchStatus): int
    {
        [$hasLocal, $hasRemote] = $branchStatus;
        $pr = $this->findAssociatedPullRequest($io);
        $this->showConfirmationMessage($io, [$targetBranch, $newBranchName], $branchStatus, $pr);
        if (! $io->confirm($this->translator->trans('branch.rename.confirm_prompt'), true)) {
            return 0;
        }
        $this->renameBranches($io, $targetBranch, $newBranchName, $branchStatus);
        $this->handlePostRenameActions($io, $pr, $targetBranch, $newBranchName);

        return 0;
    }

    protected function validateWorkingDirectory(SymfonyStyle $io): bool
    {
        $status = $this->gitRepository->getPorcelainStatus();
        if (! empty($status)) {
            $io->error($this->translator->trans('branch.rename.error_dirty_working'));

            return false;
        }

        return true;
    }

    protected function validateBranchName(string $name): bool
    {
        // Git branch name rules: no spaces, no special chars except -/_/., no .., no .lock, no @{, no backslash, no consecutive dots
        if (preg_match('/^[a-zA-Z0-9._\/-]+$/', $name) === 0) {
            return false;
        }
        if (str_contains($name, '..')) {
            return false;
        }
        if (str_ends_with($name, '.lock')) {
            return false;
        }
        // @codeCoverageIgnoreStart
        // Defensive checks: these patterns would fail the regex above, but kept for safety
        if (str_contains($name, '@{')) {
            return false;
        }
        if (str_contains($name, '\\')) {
            return false;
        }
        // Redundant check (already covered by str_contains above), but kept for consistency
        if (preg_match('/\.\./', $name)) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        return true;
    }

    protected function generateBranchNameFromKey(string $key): string
    {
        $issue = $this->jiraService->getIssue($key);
        $prefix = $this->getBranchPrefixFromIssueType($issue->issueType);
        $slugger = new AsciiSlugger();
        $slugValue = $slugger->slug($issue->title)->lower()->toString();

        return "{$prefix}/{$key}-{$slugValue}";
    }

    protected function getBranchPrefixFromIssueType(string $issueType): string
    {
        return match (strtolower($issueType)) {
            'bug' => 'fix',
            'story', 'epic' => 'feat',
            'task', 'sub-task' => 'chore',
            default => 'feat',
        };
    }

    protected function determineNewBranchName(SymfonyStyle $io, string $targetBranch, ?string $key, ?string $explicitName): ?string
    {
        if ($explicitName !== null) {
            return $this->handleExplicitName($io, $explicitName);
        }

        if ($key !== null) {
            return $this->generateBranchNameFromKeyWithErrorHandling($io, $key);
        }

        return $this->generateBranchNameFromExtractedKey($io, $targetBranch);
    }

    /**
     * @return array{behind: int, ahead: int, can_rebase: bool}
     */
    protected function checkBranchSync(string $localBranch, string $remoteBranch): array
    {
        $behind = $this->gitRepository->getBranchCommitsBehind($localBranch, $remoteBranch);
        $ahead = $this->gitRepository->getBranchCommitsAhead($localBranch, $remoteBranch);
        $canRebase = $this->gitRepository->canRebaseBranch($localBranch, $remoteBranch);

        return [
            'behind' => $behind,
            'ahead' => $ahead,
            'can_rebase' => $canRebase,
        ];
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    /**
     * @param array{0: bool, 1: bool} $branchStatus
     */
    /**
     * @param array{0: string, 1: string} $names
     * @param array{0: bool, 1: bool} $branchStatus
     * @param array<string, mixed>|null $pr
     */
    protected function showConfirmationMessage(SymfonyStyle $io, array $names, array $branchStatus, ?array $pr): void
    {
        [$oldName, $newName] = $names;
        $io->text($this->translator->trans('branch.rename.confirmation_header', ['oldName' => $oldName]));
        $io->text($this->translator->trans('branch.rename.confirmation_new', ['newName' => $newName]));
        $io->text($this->translator->trans('branch.rename.confirmation_actions'));

        $this->showBranchConfirmationDetails($io, $oldName, $newName, $branchStatus);
        $this->showPrConfirmation($io, $pr);
    }

    /**
     * @param array{0: bool, 1: bool} $branchStatus
     */
    protected function showBranchConfirmationDetails(SymfonyStyle $io, string $oldName, string $newName, array $branchStatus): void
    {
        [$hasLocal, $hasRemote] = $branchStatus;
        if ($hasLocal) {
            $io->text($this->translator->trans('branch.rename.confirmation_local', ['oldName' => $oldName, 'newName' => $newName]));
        }

        if ($hasRemote) {
            $io->text($this->translator->trans('branch.rename.confirmation_remote', ['oldName' => $oldName, 'newName' => $newName]));
        }
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    protected function showPrConfirmation(SymfonyStyle $io, ?array $pr): void
    {
        if ($pr !== null && isset($pr['number'])) {
            $io->text($this->translator->trans('branch.rename.confirmation_pr', ['number' => $pr['number']]));
        }
    }

    /**
     * @param array<string, mixed> $pr
     */
    protected function updatePullRequestAfterRename(SymfonyStyle $io, array $pr, string $oldName, string $newName): void
    {
        if (! isset($pr['number']) || $this->githubProvider === null) {
            return;
        }

        $io->text($this->translator->trans('branch.rename.creating_new_pr'));

        $submitHandler = $this->createSubmitHandler();
        $submitResult = $submitHandler->handle($io, false, null);
        if ($submitResult !== 0) {
            $io->warning($this->translator->trans('branch.rename.pr_creation_failed'));

            return;
        }

        $this->commentOnNewPullRequest($io, $oldName, $newName);
    }

    protected function createSubmitHandler(): SubmitHandler
    {
        return new SubmitHandler(
            $this->gitRepository,
            $this->jiraService,
            $this->githubProvider,
            $this->jiraConfig,
            $this->baseBranch,
            $this->translator,
            $this->logger
        );
    }

    protected function commentOnNewPullRequest(SymfonyStyle $io, string $oldName, string $newName): void
    {
        $io->text($this->translator->trans('branch.rename.commenting_pr'));

        try {
            $currentBranch = $this->gitRepository->getCurrentBranchName();
            $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
            $headBranch = $remoteOwner ? "{$remoteOwner}:{$currentBranch}" : $currentBranch;
            $newPr = $this->githubProvider->findPullRequestByBranch($headBranch);

            if ($newPr !== null && isset($newPr['number'])) {
                $comment = "Branch renamed from `{$oldName}` to `{$newName}`";
                $this->githubProvider->createComment($newPr['number'], $comment);
            }
        } catch (\Exception $e) {
            // Comment failure is not critical, continue
        }
    }

    protected function validateNewBranchNameExists(SymfonyStyle $io, string $newBranchName): bool
    {
        if ($this->gitRepository->localBranchExists($newBranchName) ||
            $this->gitRepository->remoteBranchExists('origin', $newBranchName)) {
            $io->error($this->translator->trans('branch.rename.error_new_name_exists', ['name' => $newBranchName]));

            return true;
        }

        return false;
    }

    /**
     * @return array{0: bool, 1: bool}|false|null Returns [hasLocal, hasRemote], false if user declined, or null if branch not found
     */
    protected function checkBranchExistence(SymfonyStyle $io, string $targetBranch): array|false|null
    {
        $hasLocal = $this->gitRepository->localBranchExists($targetBranch);
        $hasRemote = $this->gitRepository->remoteBranchExists('origin', $targetBranch);

        if (! $hasLocal && ! $hasRemote) {
            $io->error($this->translator->trans('branch.rename.error_branch_not_found', ['branch' => $targetBranch]));

            return null;
        }

        if ($hasRemote && ! $hasLocal) {
            $io->note($this->translator->trans('branch.rename.local_not_found_remote_exists', ['branch' => $targetBranch]));
            if (! $io->confirm($this->translator->trans('branch.rename.rename_remote_only_prompt'), true)) {
                return false;
            }
        }

        return [$hasLocal, $hasRemote];
    }

    protected function handleBranchSynchronization(SymfonyStyle $io, string $targetBranch, bool $hasLocal, bool $hasRemote): int
    {
        if (! $hasLocal || ! $hasRemote) {
            return 0;
        }

        $syncResult = $this->checkBranchSync($targetBranch, "origin/{$targetBranch}");
        if ($syncResult['behind'] > 0) {
            $io->text($this->translator->trans('branch.rename.checking_sync'));
            if ($io->confirm($this->translator->trans('branch.rename.remote_ahead_prompt', ['count' => $syncResult['behind']]), true)) {
                $io->text($this->translator->trans('branch.rename.rebasing'));

                try {
                    $this->gitRepository->rebase("origin/{$targetBranch}");
                } catch (\Exception $e) {
                    $io->error($this->translator->trans('branch.rename.rebase_failed'));
                    $io->text($this->translator->trans('branch.rename.rebase_suggestion', ['branch' => $targetBranch]));

                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findAssociatedPullRequest(SymfonyStyle $io): ?array
    {
        if ($this->githubProvider === null) {
            return null;
        }

        $io->text($this->translator->trans('branch.rename.finding_pr'));
        $currentBranch = $this->gitRepository->getCurrentBranchName();
        $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
        $headBranch = $remoteOwner ? "{$remoteOwner}:{$currentBranch}" : $currentBranch;

        try {
            return $this->githubProvider->findPullRequestByBranch($headBranch);
        } catch (\Exception $e) {
            // PR not found or error - continue without PR
            return null;
        }
    }

    /**
     * @param array{0: bool, 1: bool} $branchStatus
     */
    protected function renameBranches(SymfonyStyle $io, string $targetBranch, string $newBranchName, array $branchStatus): void
    {
        [$hasLocal, $hasRemote] = $branchStatus;
        if ($hasLocal) {
            $io->text($this->translator->trans('branch.rename.renaming_local'));
            $this->gitRepository->renameLocalBranch($targetBranch, $newBranchName);
        }

        if ($hasRemote) {
            $io->text($this->translator->trans('branch.rename.renaming_remote'));

            try {
                $this->gitRepository->renameRemoteBranch($targetBranch, $newBranchName, 'origin');
            } catch (\Exception $e) {
                $io->warning($this->translator->trans('branch.rename.remote_not_found'));
            }
        }
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    protected function handlePostRenameActions(SymfonyStyle $io, ?array $pr, string $targetBranch, string $newBranchName): void
    {
        if ($pr !== null && $this->githubProvider !== null) {
            $this->updatePullRequestAfterRename($io, $pr, $targetBranch, $newBranchName);
        }

        $io->success($this->translator->trans('branch.rename.success', ['oldName' => $targetBranch, 'newName' => $newBranchName]));

        if ($pr === null && $this->githubProvider !== null) {
            $io->note($this->translator->trans('branch.rename.no_pr_found'));
            if ($io->confirm($this->translator->trans('branch.rename.create_pr_prompt'), true)) {
                $io->text($this->translator->trans('branch.rename.switching_for_submit'));
                $io->text("Run 'stud submit' to create a Pull Request.");
            }
        }
    }

    protected function handleExplicitName(SymfonyStyle $io, string $explicitName): ?string
    {
        if (! $this->validateBranchName($explicitName)) {
            $io->error($this->translator->trans('branch.rename.error_invalid_name', ['name' => $explicitName]));

            return null;
        }

        return $explicitName;
    }

    protected function generateBranchNameFromKeyWithErrorHandling(SymfonyStyle $io, string $key): ?string
    {
        $io->text($this->translator->trans('branch.rename.fetching_issue'));

        try {
            $io->text($this->translator->trans('branch.rename.generating_name'));

            return $this->generateBranchNameFromKey($key);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('branch.rename.error_key_not_found', ['key' => $key]));

            return null;
        }
    }

    protected function generateBranchNameFromExtractedKey(SymfonyStyle $io, string $targetBranch): ?string
    {
        $extractedKey = $this->gitRepository->getJiraKeyFromBranchName();
        if ($extractedKey === null) {
            $io->error($this->translator->trans('branch.rename.error_no_key_in_branch', ['branch' => $targetBranch]));

            return null;
        }

        return $this->generateBranchNameFromKeyWithErrorHandling($io, $extractedKey);
    }

    protected function looksLikeJiraKey(string $value): bool
    {
        // Jira keys follow the pattern: PROJECT-123 (e.g., SCI-34, PROJ-123)
        return (bool) preg_match('/^[A-Z]+-\d+$/', strtoupper($value));
    }
}
