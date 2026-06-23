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
use App\Guard\Capability\WorkItemJiraAware;
use App\Response\CommandResponse;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Prompt\PromptInterface;

class CommitHandler implements GitRepositoryAware, WorkItemJiraAware
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly string $baseBranch,
        mixed $_translator,
        private readonly PromptInterface $prompt
    ) {
        unset($_translator);
    }

    public function handle(mixed $first, mixed $second = null, mixed $third = null, mixed $fourth = false, mixed $fifth = false): CommandResponse|int
    {
        [$isNew, $message, $stageAll, $quiet] = $this->normalizeHandleArguments($first, $second, $third, $fourth, $fifth);
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (empty(trim($gitStatus))) {
            return CommandResponse::success(
                messages: [ResponseMessage::notice(MessageRef::key('commit.note_clean_working_tree'))],
            );
        }

        if (! empty($message)) {
            return $this->commitWithMessage($message, $stageAll);
        }

        $latestLogicalSha = $this->resolveLatestLogicalSha($isNew);
        if ($latestLogicalSha !== null) {
            return $this->commitFixupForSha($latestLogicalSha, $stageAll);
        }

        return $this->commitWithJiraPrompt($stageAll, $quiet);
    }

    /**
     * @return array{0: bool, 1: string|null, 2: bool, 3: bool}
     */
    private function normalizeHandleArguments(mixed $first, mixed $second, mixed $third, mixed $fourth, mixed $fifth): array
    {
        if ($first instanceof \Symfony\Component\Console\Style\SymfonyStyle) {
            return [(bool) $second, is_string($third) ? $third : null, (bool) $fourth, (bool) $fifth];
        }

        return [(bool) $first, is_string($second) ? $second : null, (bool) $third, (bool) $fourth];
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

    protected function commitFixupForSha(string $latestLogicalSha, bool $stageAll): CommandResponse
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

        return CommandResponse::success(
            MessageRef::key('commit.fixup_success', ['sha' => $latestLogicalSha]),
            ['fixupSha' => $latestLogicalSha],
        );
    }

    protected function commitWithJiraPrompt(bool $stageAll, bool $quiet): CommandResponse
    {
        $messages = [ResponseMessage::notice(MessageRef::key('commit.note_no_logical'))];

        $key = $this->gitRepository->getJiraKeyFromBranchName();
        if (! $key) {
            return CommandResponse::error(MessageRef::key('commit.error_no_key'), $messages);
        }

        $issueResult = $this->fetchIssueForCommit($key);
        if ($issueResult instanceof CommandResponse) {
            return $issueResult;
        }
        $issue = $issueResult;

        $detectedType = $this->getCommitTypeFromIssueType($issue->issueType);
        $defaultScope = ! empty($issue->components) ? $issue->components[0] : null;
        if ($quiet) {
            $type = $detectedType;
            $scope = $defaultScope;
            $summary = $issue->title;
        } else {
            [$type, $scope, $summary] = $this->promptTypeScopeSummary($issue, $detectedType, $defaultScope);
            $type = $type ?? $detectedType;
            $scope = $scope ?? $defaultScope;
            $summary = $summary ?? $issue->title;
        }

        $commitMessage = "{$type}" . ($scope ? "({$scope})" : '') . ": {$summary} [{$key}]";

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
            return $this->jiraService->getIssue($key);
        } catch (ApiException $e) {
            $error = MessageRef::key('commit.error_not_found', ['key' => $key]);

            return CommandResponse::error(
                $error,
                [ResponseMessage::error($error, $e->getTechnicalDetails())],
            );
        } catch (\Exception $e) {
            return CommandResponse::error(MessageRef::key('commit.error_not_found', ['key' => $key]));
        }
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    protected function promptTypeScopeSummary(WorkItem $issue, string $detectedType, ?string $defaultScope): array
    {
        $scopePrompt = ! empty($issue->components)
            ? MessageRef::key('commit.scope_auto', ['scope' => $defaultScope])
            : MessageRef::key('commit.scope_prompt');
        $type = $this->prompt->ask(MessageRef::key('commit.type_prompt', ['type' => $detectedType]), $detectedType);
        $scope = $this->prompt->ask($scopePrompt, $defaultScope);
        $summary = $this->prompt->ask(MessageRef::key('commit.summary_prompt'), $issue->title);

        return [$type, $scope, $summary];
    }

    protected function getCommitTypeFromIssueType(string $issueType): string
    {
        return match (strtolower($issueType)) {
            'bug' => 'fix',
            'story', 'epic' => 'feat',
            'task', 'sub-task' => 'chore',
            default => 'feat',
        };
    }

    protected function hasStagedChanges(): bool
    {
        $process = $this->gitRepository->runQuietly('git diff --cached --quiet');

        // git diff --cached --quiet returns 0 if there are no staged changes, 1 if there are staged changes
        // So we return !isSuccessful() to indicate if there are staged changes
        return ! $process->isSuccessful();
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
