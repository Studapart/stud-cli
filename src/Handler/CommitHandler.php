<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\DTO\WorkItem;
use App\Exception\ApiException;
use App\Exception\GitException;
use App\Exception\GitTimeoutException;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Response\CommandResponse;
use App\Service\CommitMessageComposer;
use App\Service\GitRepository;
use App\Service\IssueTrackerReadPort;
use App\Service\Prompt\PromptInterface;

class CommitHandler implements GitRepositoryAware, JiraAware
{
    private readonly CommitMessageComposer $messageComposer;

    /**
     * @param IssueTrackerReadPort|\Closure(): IssueTrackerReadPort $issueTracker
     *        Closure defers provider resolution until a work-item fetch is required
     */
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly IssueTrackerReadPort|\Closure $issueTracker,
        private readonly string $baseBranch,
        PromptInterface $prompt
    ) {
        $this->messageComposer = new CommitMessageComposer($prompt);
    }

    public function handle(mixed $isNew, mixed $message = null, mixed $stageAll = null, mixed $quiet = false): CommandResponse|int
    {
        $isNew = (bool) $isNew;
        $message = is_string($message) ? $message : null;
        $stageAll = (bool) $stageAll;
        $quiet = (bool) $quiet;
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (empty(trim($gitStatus))) {
            return CommandResponse::success(
                messages: [ResponseMessage::notice(MessageRef::key('commit.note_clean_working_tree'))],
            );
        }

        $latestLogicalSha = $this->resolveLatestLogicalSha($isNew);
        if ($latestLogicalSha !== null) {
            return $this->commitFixupForSha($latestLogicalSha, $stageAll, $message);
        }

        if (! empty($message)) {
            return $this->commitWithMessage($message, $stageAll);
        }

        return $this->commitWithJiraPrompt($stageAll, $quiet);
    }

    protected function commitWithMessage(string $message, bool $stageAll): CommandResponse
    {
        if (! $stageAll && ! $this->hasStagedChanges()) {
            return CommandResponse::error(MessageRef::key('commit.no_staged_changes'));
        }

        $gitError = $this->executeGitCommit(function () use ($message, $stageAll): void {
            if ($stageAll) {
                $this->gitRepository->stageAllChanges();
            }
            $this->gitRepository->commit($message);
        });
        if ($gitError !== null) {
            return $gitError;
        }

        return CommandResponse::success(MessageRef::key('commit.success'), ['commitMessage' => $message]);
    }

    protected function resolveLatestLogicalSha(bool $isNew): ?string
    {
        if ($isNew) {
            return null;
        }
        $sha = $this->gitRepository->findLatestLogicalSha($this->baseBranch);

        return $sha;
    }

    protected function commitFixupForSha(string $latestLogicalSha, bool $stageAll, ?string $unusedMessage = null): CommandResponse
    {
        if (! $stageAll && ! $this->hasStagedChanges()) {
            return CommandResponse::error(MessageRef::key('commit.no_staged_changes'));
        }

        $gitError = $this->executeGitCommit(function () use ($latestLogicalSha, $stageAll): void {
            if ($stageAll) {
                $this->gitRepository->stageAllChanges();
            }
            $this->gitRepository->commitFixup($latestLogicalSha);
        });
        if ($gitError !== null) {
            return $gitError;
        }

        $messages = [];
        if ($unusedMessage !== null && $unusedMessage !== '') {
            $messages[] = ResponseMessage::notice(MessageRef::key('commit.note_message_unused'));
        }

        return CommandResponse::success(
            MessageRef::key('commit.fixup_success', ['sha' => $latestLogicalSha]),
            ['fixupSha' => $latestLogicalSha],
            $messages,
        );
    }

    protected function commitWithJiraPrompt(bool $stageAll, bool $quiet): CommandResponse
    {
        $messages = [ResponseMessage::notice(MessageRef::key('commit.note_no_logical'))];

        $key = $this->gitRepository->getIssueKeyFromBranchName();
        if (! $key) {
            return CommandResponse::error(MessageRef::key('commit.error_no_key'), $messages);
        }

        $issueResult = $this->fetchIssueForCommit($key);
        if ($issueResult instanceof CommandResponse) {
            return $issueResult;
        }
        $commitMessage = $this->messageComposer->compose($issueResult, $key, $quiet);

        if (! $stageAll && ! $this->hasStagedChanges()) {
            return CommandResponse::error(MessageRef::key('commit.no_staged_changes'), $messages);
        }

        $gitError = $this->executeGitCommit(function () use ($stageAll, $commitMessage): void {
            if ($stageAll) {
                $this->gitRepository->stageAllChanges();
            }
            $this->gitRepository->commit($commitMessage);
        }, $messages);
        if ($gitError !== null) {
            return $gitError;
        }

        return CommandResponse::success(
            MessageRef::key('commit.success'),
            ['jiraKey' => $key, 'commitMessage' => $commitMessage],
            $messages,
        );
    }

    protected function fetchIssueForCommit(string $key): WorkItem|CommandResponse
    {
        try {
            return $this->port()->getIssue($key);
        } catch (ApiException $e) {
            $error = $e->getResolutionHint()
                ?? MessageRef::key('commit.error_not_found', ['key' => $key]);

            return CommandResponse::error(
                $error,
                [ResponseMessage::error($error, $e->getTechnicalDetails())],
            );
        }
    }

    /**
     * Resolve the issue-tracker port (eager instance or deferred Closure from Castor).
     */
    protected function port(): IssueTrackerReadPort
    {
        if ($this->issueTracker instanceof IssueTrackerReadPort) {
            return $this->issueTracker;
        }

        return ($this->issueTracker)();
    }

    protected function hasStagedChanges(): bool
    {
        return $this->gitRepository->hasStagedChanges();
    }

    /**
     * @param list<ResponseMessage> $messages
     */
    private function executeGitCommit(callable $operation, array $messages = []): ?CommandResponse
    {
        try {
            $operation();
        } catch (GitTimeoutException $e) {
            $error = MessageRef::key('git.error.timeout', [
                '%seconds%' => (string) (int) $e->getTimeoutSeconds(),
                '%command%' => $e->getCommand(),
            ]);

            return CommandResponse::error(
                $error,
                $this->withErrorMessage($messages, ResponseMessage::error($error, $e->getTechnicalDetails())),
            );
        } catch (GitException $e) {
            $error = MessageRef::key('commit.error_git', ['error' => $e->getMessage()]);

            return CommandResponse::error(
                $error,
                $this->withErrorMessage($messages, ResponseMessage::error($error, $e->getTechnicalDetails())),
            );
        }

        return null;
    }

    /**
     * @param list<ResponseMessage> $messages
     *
     * @return list<ResponseMessage>
     */
    private function withErrorMessage(array $messages, ResponseMessage $errorMessage): array
    {
        $messages[] = $errorMessage;

        return $messages;
    }
}
