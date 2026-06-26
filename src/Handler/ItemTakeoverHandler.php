<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Enum\WorkflowChannel;
use App\Exception\ApiException;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Response\WorkflowResponse;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\Prompt\PromptInterface;

class ItemTakeoverHandler implements GitRepositoryAware, ProjectBaseBranchAware, WorkItemJiraAware
{
    private WorkflowEntryRecorder $recorder;

    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly GitBranchService $gitBranchService,
        private readonly IssueTrackerPort $provider,
        private readonly ItemStartHandler $itemStartHandler,
        private readonly string $baseBranch,
        mixed $_translator,
        /** @phpstan-ignore-next-line */
        private readonly array $jiraConfig,
        private readonly PromptInterface $prompt,
    ) {
        unset($_translator);
        // $jiraConfig is kept for potential future use (e.g., transition handling)
    }

    public function handle(string $key, bool $quiet = false): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $key = strtoupper($key);
        $this->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.section', ['key' => $key]));

        // Step 1: Validate working directory
        if (! $this->checkWorkingDirectory()) {
            return $this->recorder->toResponse(1);
        }

        // Step 2: Fetch issue from Jira
        try {
            $issue = $this->provider->getIssue($key);
        } catch (ApiException $e) {
            $this->recorder->addErrorWithDetails(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('item.takeover.error_not_found', ['key' => $key]),
                $e->getTechnicalDetails()
            );

            return $this->recorder->toResponse(1);
        }

        // Step 3: Assign issue to current user
        $this->assignIssueToCurrentUser($key);

        // Step 4: Fetch from remote
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.fetching'), WorkflowChannel::Git);
        $this->gitRepository->fetch();

        // Step 5: Search for branches
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.searching_branches'), WorkflowChannel::Git);
        $branches = $this->gitBranchService->findBranchesByIssueKey($key);

        // Step 6: Handle branches
        if (empty($branches['local']) && empty($branches['remote'])) {
            return $this->handleNoBranches($key, $quiet);
        }

        return $this->handleExistingBranches($key, $branches, $quiet);
    }

    protected function checkWorkingDirectory(): bool
    {
        $status = $this->gitRepository->getPorcelainStatus();
        if (! empty(trim($status))) {
            $this->recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.error_dirty_working'));

            return false;
        }

        return true;
    }

    protected function assignIssueToCurrentUser(string $key): void
    {
        try {
            $this->recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.takeover.assigning', ['key' => $key]), WorkflowChannel::Jira);
            $this->provider->assign($key);
        } catch (ApiException $e) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.assign_warning', ['error' => $e->getMessage()]));
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.assign_warning', ['error' => $e->getMessage()]));
        }
    }

    protected function handleNoBranches(string $key, bool $quiet = false): WorkflowResponse
    {
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.no_branches', ['key' => $key]));

        $startFresh = ! $quiet && $this->prompt->confirm(
            MessageRef::key('item.takeover.start_fresh_prompt'),
            true
        );

        if ($startFresh) {
            $takeoverPartial = $this->recorder->toResponse(0);
            $startResponse = $this->itemStartHandler->handle($key);

            return WorkflowResponse::fromExitCode(
                $startResponse->exitCode,
                array_merge($takeoverPartial->entries, $startResponse->entries),
                array_merge($takeoverPartial->getMessages(), $startResponse->getMessages()),
            );
        }

        return $this->recorder->toResponse(0);
    }

    /**
     * @param array{local: array<string>, remote: array<string>} $branches
     */
    protected function handleExistingBranches(string $key, array $branches, bool $quiet = false): WorkflowResponse
    {
        $selectedBranch = $this->selectBranch($branches, $quiet);

        if ($selectedBranch === null) {
            return $this->recorder->toResponse(0);
        }

        $currentBranch = $this->gitRepository->getCurrentBranchName();

        // Check if already on target branch
        if ($currentBranch === $selectedBranch) {
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.already_on_branch', ['branch' => $selectedBranch]));
        } else {
            $this->switchToBranch($selectedBranch, $branches);
        }

        // Get branch status
        $remoteBranch = $this->getRemoteBranchName($selectedBranch, $branches);
        $status = $this->gitBranchService->getBranchStatus($selectedBranch, $this->baseBranch, $remoteBranch);

        // Check if branch is based on correct base
        $isBasedOnCorrectBase = $this->gitBranchService->isBranchBasedOn($selectedBranch, $this->baseBranch);
        if (! $isBasedOnCorrectBase) {
            $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.warning_wrong_base', ['base' => $this->baseBranch]));
        }

        // Handle branch synchronization
        $this->handleBranchSynchronization($selectedBranch, $status, $remoteBranch);

        // Show success message
        $this->showSuccessMessage($key, $selectedBranch, $status, $this->baseBranch);

        return $this->recorder->toResponse(0);
    }

    /**
     * @param array{local: array<string>, remote: array<string>} $branches
     */
    protected function selectBranch(array $branches, bool $quiet = false): ?string
    {
        $allBranches = $this->combineBranches($branches);

        if (count($allBranches) === 1) {
            return $this->handleSingleBranch($allBranches[0], $quiet);
        }

        return $this->handleMultipleBranches($allBranches, $quiet);
    }

    /**
     * @param array{name: string, is_remote: bool} $branch
     */
    protected function handleSingleBranch(array $branch, bool $quiet = false): ?string
    {
        if ($branch['is_remote']) {
            $confirmed = $quiet || $this->prompt->confirm(
                MessageRef::key('item.takeover.confirm_branch', ['branch' => $branch['name']]),
                true
            );

            return $confirmed ? $branch['name'] : null;
        }

        return $branch['name'];
    }

    /**
     * @param array<int, array{name: string, is_remote: bool}> $allBranches
     */
    protected function handleMultipleBranches(array $allBranches, bool $quiet = false): ?string
    {
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.branches_found', ['count' => count($allBranches)]));

        if ($quiet) {
            // First option (remotes first, then locals - same order as combineBranches)
            $first = $allBranches[0] ?? null;

            return $first !== null ? $first['name'] : null;
        }

        $options = $this->buildBranchOptions($allBranches);

        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.select_branch'));
        $selected = $this->prompt->choice('', $options);

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
            $options[] = $this->branchChoiceLabel($branch);
        }

        return $options;
    }

    /**
     * @param array<int, array{name: string, is_remote: bool}> $allBranches
     */
    protected function extractBranchNameFromSelection(array $allBranches, string $selected): ?string
    {
        foreach ($allBranches as $branch) {
            if ($this->branchChoiceLabel($branch) === $selected) {
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
    protected function switchToBranch(string $branchName, array $branches): void
    {
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.switching', ['branch' => $branchName]), WorkflowChannel::Git);

        if (in_array($branchName, $branches['local'], true)) {
            $this->gitBranchService->switchBranch($branchName);
        } elseif (in_array($branchName, $branches['remote'], true)) {
            $this->gitBranchService->switchToRemoteBranch($branchName);
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
    protected function handleBranchSynchronization(string $branchName, array $status, ?string $remoteBranch): void
    {
        if ($remoteBranch === null) {
            return;
        }

        if ($status['behind_remote'] > 0) {
            $hasLocalCommits = $status['ahead_remote'] > 0;

            if ($hasLocalCommits) {
                $this->recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.warning_diverged'));
            } else {
                $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.pulling'), WorkflowChannel::Git);
                $this->gitRepository->pullWithRebase('origin', $branchName);
            }
        }
    }

    /**
     * @param array{behind_remote: int, ahead_remote: int, behind_base: int, ahead_base: int} $status
     */
    protected function showSuccessMessage(string $key, string $branchName, array $status, string $baseBranch): void
    {
        $this->recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.success_took_over', ['key' => $key]));
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.success_on_branch', ['branch' => $branchName]));

        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.success_status_header'));

        $remoteStatus = $this->formatRemoteStatus($status);
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.success_status_remote', ['status' => $remoteStatus]));

        $baseStatus = $this->formatBaseStatus($status, $baseBranch);
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.success_status_base', ['base' => $baseBranch, 'status' => $baseStatus]));

        $this->recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.takeover.success_view_details', ['key' => $key]));
    }

    /**
     * @param array{behind_remote: int, ahead_remote: int, behind_base: int, ahead_base: int} $status
     */
    protected function formatRemoteStatus(array $status): MessageRef
    {
        if ($status['behind_remote'] > 0) {
            return MessageRef::key('item.takeover.status_behind_remote', ['count' => $status['behind_remote']]);
        }

        if ($status['ahead_remote'] > 0) {
            return MessageRef::key('item.takeover.status_ahead_remote', ['count' => $status['ahead_remote']]);
        }

        return MessageRef::key('item.takeover.status_sync_remote');
    }

    /**
     * @param array{behind_remote: int, ahead_remote: int, behind_base: int, ahead_base: int} $status
     */
    protected function formatBaseStatus(array $status, string $baseBranch): MessageRef
    {
        if ($status['behind_base'] > 0) {
            return MessageRef::key('item.takeover.status_behind_base', ['count' => $status['behind_base'], 'base' => $baseBranch]);
        }

        if ($status['ahead_base'] > 0) {
            return MessageRef::key('item.takeover.status_ahead_base', ['count' => $status['ahead_base'], 'base' => $baseBranch]);
        }

        return MessageRef::key('item.takeover.status_sync_base', ['base' => $baseBranch]);
    }

    /**
     * @param array{name: string, is_remote: bool} $branch
     */
    private function branchChoiceLabel(array $branch): string
    {
        return $branch['is_remote'] ? "{$branch['name']} (remote)" : "{$branch['name']} (local)";
    }
}
