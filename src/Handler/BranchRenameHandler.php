<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Enum\WorkflowChannel;
use App\Guard\Capability\GitRepositoryAware;
use App\Response\WorkflowResponse;
use App\Service\BranchNameGenerator;
use App\Service\BranchNameValidator;
use App\Service\BranchRenamePrCoordinator;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;

class BranchRenameHandler implements GitRepositoryAware
{
    private WorkflowEntryRecorder $recorder;

    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly BranchNameGenerator $branchNameGenerator,
        private readonly BranchNameValidator $branchNameValidator,
        private readonly BranchRenamePrCoordinator $prCoordinator,
        private readonly PromptInterface $prompt,
    ) {
    }

    public function handle(?string $branchName, ?string $key, ?string $explicitName, bool $quiet = false): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $this->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.section'));

        if (! $this->validateWorkingDirectory()) {
            return $this->recorder->toResponse(1);
        }

        [$targetBranch, $key] = $this->normalizeBranchAndKey($branchName, $key);
        $newBranchName = $this->determineNewBranchName($targetBranch, $key, $explicitName);
        if ($newBranchName === null || $this->validateNewBranchNameExists($newBranchName)) {
            return $this->recorder->toResponse(1);
        }

        $branchStatus = $this->checkBranchExistence($targetBranch, $quiet);
        if ($branchStatus === null || $branchStatus === false) {
            return $this->recorder->toResponse($branchStatus === false ? 0 : 1);
        }

        [$hasLocal, $hasRemote] = $branchStatus;
        if ($this->handleBranchSynchronization($targetBranch, $hasLocal, $hasRemote, $quiet) === 1) {
            return $this->recorder->toResponse(1);
        }

        return $this->recorder->toResponse($this->performRename($targetBranch, $newBranchName, $branchStatus, $quiet));
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
    protected function performRename(string $targetBranch, string $newBranchName, array $branchStatus, bool $quiet = false): int
    {
        [$hasLocal, $hasRemote] = $branchStatus;
        $pr = $this->findAssociatedPullRequest();
        $this->showConfirmationMessage([$targetBranch, $newBranchName], $branchStatus, $pr);
        if (! $quiet && ! $this->prompt->confirm(MessageRef::key('branch.rename.confirm_prompt'), true)) {
            return 0;
        }
        $this->renameBranches($targetBranch, $newBranchName, $branchStatus);
        $this->handlePostRenameActions($pr, $targetBranch, $newBranchName, $quiet);

        return 0;
    }

    protected function validateWorkingDirectory(): bool
    {
        $status = $this->gitRepository->getPorcelainStatus();
        if (! empty($status)) {
            $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.error_dirty_working'));

            return false;
        }

        return true;
    }

    protected function validateBranchName(string $name): bool
    {
        return $this->branchNameValidator->validateBranchName($name);
    }

    protected function generateBranchNameFromKey(string $key): string
    {
        return $this->branchNameGenerator->generateBranchNameFromKey($key);
    }

    protected function getBranchPrefixFromIssueType(string $issueType): string
    {
        return $this->branchNameGenerator->getBranchPrefixFromIssueType($issueType);
    }

    protected function determineNewBranchName(string $targetBranch, ?string $key, ?string $explicitName): ?string
    {
        if ($explicitName !== null) {
            return $this->handleExplicitName($explicitName);
        }

        if ($key !== null) {
            return $this->generateBranchNameFromKeyWithErrorHandling($key);
        }

        return $this->generateBranchNameFromExtractedKey($targetBranch);
    }

    /**
     * @return array{behind: int, ahead: int, can_rebase: bool}
     */
    protected function checkBranchSync(string $localBranch, string $remoteBranch): array
    {
        $behind = $this->gitBranchService->getBranchCommitsBehind($localBranch, $remoteBranch);
        $ahead = $this->gitBranchService->getBranchCommitsAhead($localBranch, $remoteBranch);
        $canRebase = $this->gitBranchService->canRebaseBranch($localBranch, $remoteBranch);

        return [
            'behind' => $behind,
            'ahead' => $ahead,
            'can_rebase' => $canRebase,
        ];
    }

    /**
     * @param array{0: string, 1: string} $names
     * @param array{0: bool, 1: bool} $branchStatus
     * @param array<string, mixed>|null $pr
     */
    protected function showConfirmationMessage(array $names, array $branchStatus, ?array $pr): void
    {
        [$oldName, $newName] = $names;
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.confirmation_header', ['oldName' => $oldName]));
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.confirmation_new', ['newName' => $newName]));
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.confirmation_actions'));

        $this->showBranchConfirmationDetails($oldName, $newName, $branchStatus);
        $this->showPrConfirmation($pr);
    }

    /**
     * @param array{0: bool, 1: bool} $branchStatus
     */
    protected function showBranchConfirmationDetails(string $oldName, string $newName, array $branchStatus): void
    {
        [$hasLocal, $hasRemote] = $branchStatus;
        if ($hasLocal) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.confirmation_local', ['oldName' => $oldName, 'newName' => $newName]));
        }

        if ($hasRemote) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.confirmation_remote', ['oldName' => $oldName, 'newName' => $newName]));
        }
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    protected function showPrConfirmation(?array $pr): void
    {
        if ($pr !== null && isset($pr['number'])) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.confirmation_pr', ['number' => $pr['number']]));
        }
    }

    /**
     * @param array<string, mixed> $pr
     */
    protected function updatePullRequestAfterRename(array $pr, string $oldName, string $newName): void
    {
        $this->prCoordinator->updatePullRequestAfterRename($this->recorder, $pr, $oldName, $newName);
    }

    protected function createSubmitHandler(): SubmitHandler
    {
        return $this->prCoordinator->createSubmitHandler();
    }

    protected function commentOnNewPullRequest(string $oldName, string $newName): void
    {
        $this->prCoordinator->commentOnNewPullRequest($this->recorder, $oldName, $newName);
    }

    protected function validateNewBranchNameExists(string $newBranchName): bool
    {
        if ($this->gitRepository->localBranchExists($newBranchName) ||
            $this->gitRepository->remoteBranchExists('origin', $newBranchName)) {
            $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.error_new_name_exists', ['name' => $newBranchName]));

            return true;
        }

        return false;
    }

    /**
     * @return array{0: bool, 1: bool}|false|null
     */
    protected function checkBranchExistence(string $targetBranch, bool $quiet = false): array|false|null
    {
        $hasLocal = $this->gitRepository->localBranchExists($targetBranch);
        $hasRemote = $this->gitRepository->remoteBranchExists('origin', $targetBranch);

        if (! $hasLocal && ! $hasRemote) {
            $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.error_branch_not_found', ['branch' => $targetBranch]));

            return null;
        }

        if ($hasRemote && ! $hasLocal) {
            $this->recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.local_not_found_remote_exists', ['branch' => $targetBranch]));
            if (! $quiet && ! $this->prompt->confirm(MessageRef::key('branch.rename.rename_remote_only_prompt'), true)) {
                return false;
            }
        }

        return [$hasLocal, $hasRemote];
    }

    protected function handleBranchSynchronization(string $targetBranch, bool $hasLocal, bool $hasRemote, bool $quiet = false): int
    {
        if (! $hasLocal || ! $hasRemote) {
            return 0;
        }

        $syncResult = $this->checkBranchSync($targetBranch, "origin/{$targetBranch}");
        if ($syncResult['behind'] > 0) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.checking_sync'), WorkflowChannel::Git);
            $doRebase = $quiet || $this->prompt->confirm(MessageRef::key('branch.rename.remote_ahead_prompt', ['count' => $syncResult['behind']]), true);
            if ($doRebase) {
                $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.rebasing'), WorkflowChannel::Git);

                try {
                    $this->gitRepository->rebase("origin/{$targetBranch}");
                } catch (\Exception $e) {
                    $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.rebase_failed'));
                    $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.rebase_suggestion', ['branch' => $targetBranch]));

                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findAssociatedPullRequest(): ?array
    {
        return $this->prCoordinator->findAssociatedPullRequest($this->recorder);
    }

    /**
     * @param array{0: bool, 1: bool} $branchStatus
     */
    protected function renameBranches(string $targetBranch, string $newBranchName, array $branchStatus): void
    {
        [$hasLocal, $hasRemote] = $branchStatus;
        if ($hasLocal) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.renaming_local'), WorkflowChannel::Git);
            $this->gitBranchService->renameLocalBranch($targetBranch, $newBranchName);
        }

        if ($hasRemote) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.renaming_remote'), WorkflowChannel::Git);

            try {
                $this->gitBranchService->renameRemoteBranch($targetBranch, $newBranchName, 'origin');
            } catch (\Exception $e) {
                $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.remote_not_found'));
            }
        }
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    protected function handlePostRenameActions(?array $pr, string $targetBranch, string $newBranchName, bool $quiet = false): void
    {
        $this->prCoordinator->handlePostRenameActions($this->recorder, $pr, $targetBranch, $newBranchName, $quiet);
    }

    protected function handleExplicitName(string $explicitName): ?string
    {
        if (! $this->validateBranchName($explicitName)) {
            $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.error_invalid_name', ['name' => $explicitName]));

            return null;
        }

        return $explicitName;
    }

    protected function generateBranchNameFromKeyWithErrorHandling(string $key): ?string
    {
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.fetching_issue'), WorkflowChannel::Jira);

        try {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.generating_name'), WorkflowChannel::Jira);

            return $this->generateBranchNameFromKey($key);
        } catch (\Exception $e) {
            $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.error_key_not_found', ['key' => $key]));

            return null;
        }
    }

    protected function generateBranchNameFromExtractedKey(string $targetBranch): ?string
    {
        $extractedKey = $this->gitRepository->getJiraKeyFromBranchName();
        if ($extractedKey === null) {
            $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.error_no_key_in_branch', ['branch' => $targetBranch]));

            return null;
        }

        return $this->generateBranchNameFromKeyWithErrorHandling($extractedKey);
    }

    protected function looksLikeJiraKey(string $value): bool
    {
        return $this->branchNameValidator->looksLikeJiraKey($value);
    }
}
