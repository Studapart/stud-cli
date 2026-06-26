<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\SubmitOptions;
use App\Enum\WorkflowChannel;
use App\Handler\SubmitHandler;
use App\Service\Prompt\PromptInterface;

class BranchRenamePrCoordinator
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly IssueTrackerPort $workItemProvider,
        private readonly ?GitHostingPort $githubProvider,
        private readonly array $jiraConfig,
        private readonly string $baseBranch,
        private readonly mixed $translator,
        private readonly PromptInterface $prompt,
        private readonly CanConvertToMarkdownInterface $htmlConverter,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAssociatedPullRequest(WorkflowEntryRecorder $recorder): ?array
    {
        if ($this->githubProvider === null) {
            return null;
        }

        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.finding_pr'), WorkflowChannel::Git);
        $currentBranch = $this->gitRepository->getCurrentBranchName();
        $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
        $headBranch = $remoteOwner ? "{$remoteOwner}:{$currentBranch}" : $currentBranch;

        try {
            return $this->githubProvider->findPullRequestByBranch($headBranch);
        } catch (\Exception $e) {
            RecoverableExceptionLogger::logToRecorder(
                $recorder,
                $e,
                'Failed to find pull request for branch',
                WorkflowChannel::Git,
            );

            return null;
        }
    }

    /**
     * @param array<string, mixed> $pr
     */
    public function updatePullRequestAfterRename(
        WorkflowEntryRecorder $recorder,
        array $pr,
        string $oldName,
        string $newName,
    ): void {
        if (! isset($pr['number']) || $this->githubProvider === null) {
            return;
        }

        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.creating_new_pr'), WorkflowChannel::Git);

        $submitHandler = $this->createSubmitHandler();
        $submitResult = $submitHandler->handle(new SubmitOptions());
        if ($submitResult->exitCode !== 0) {
            $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.pr_creation_failed'));

            return;
        }

        $this->commentOnNewPullRequest($recorder, $oldName, $newName);
    }

    public function createSubmitHandler(): SubmitHandler
    {
        return new SubmitHandler(
            $this->gitRepository,
            $this->workItemProvider,
            $this->githubProvider,
            $this->jiraConfig,
            $this->baseBranch,
            $this->translator,
            $this->prompt,
            $this->htmlConverter,
        );
    }

    public function commentOnNewPullRequest(WorkflowEntryRecorder $recorder, string $oldName, string $newName): void
    {
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.commenting_pr'), WorkflowChannel::Git);

        try {
            $currentBranch = $this->gitRepository->getCurrentBranchName();
            $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
            $headBranch = $remoteOwner ? "{$remoteOwner}:{$currentBranch}" : $currentBranch;
            $newPr = $this->githubProvider?->findPullRequestByBranch($headBranch);

            if ($newPr !== null && isset($newPr['number'])) {
                $comment = "Branch renamed from `{$oldName}` to `{$newName}`";
                $this->githubProvider->createComment($newPr['number'], $comment);
            }
        } catch (\Exception $e) {
            RecoverableExceptionLogger::logToRecorder(
                $recorder,
                $e,
                'Failed to comment on pull request after branch rename',
                WorkflowChannel::Git,
            );
        }
    }

    /**
     * @param array<string, mixed>|null $pr
     */
    public function handlePostRenameActions(
        WorkflowEntryRecorder $recorder,
        ?array $pr,
        string $targetBranch,
        string $newBranchName,
        bool $quiet,
    ): void {
        if ($pr !== null && $this->githubProvider !== null) {
            $this->updatePullRequestAfterRename($recorder, $pr, $targetBranch, $newBranchName);
        }

        $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.success', ['oldName' => $targetBranch, 'newName' => $newBranchName]));

        if ($pr === null && $this->githubProvider !== null) {
            $recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.no_pr_found'));
            if ($quiet || $this->prompt->confirm(MessageRef::key('branch.rename.create_pr_prompt'), true)) {
                $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('branch.rename.switching_for_submit'), WorkflowChannel::Git);
                $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, "Run 'stud submit' to create a Pull Request.");
            }
        }
    }
}
