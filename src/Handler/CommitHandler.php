<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\WorkItem;
use App\Exception\ApiException;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class CommitHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly string $baseBranch,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io, bool $isNew, ?string $message, bool $stageAll = false, bool $quiet = false): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.section'));

        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (empty(trim($gitStatus))) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.note_clean_working_tree'));

            return 0;
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

    protected function commitWithMessage(string $message, bool $stageAll): int
    {
        if (! $stageAll && ! $this->hasStagedChanges()) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('commit.no_staged_changes')));

            return 1;
        }
        if ($stageAll) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.staging_conditional'));
            $this->gitRepository->stageAllChanges();
        }
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.committing'));
        $this->gitRepository->commit($message);
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.success'));

        return 0;
    }

    protected function resolveLatestLogicalSha(bool $isNew): ?string
    {
        if ($isNew) {
            return null;
        }
        $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, '  ' . $this->translator->trans('commit.checking_logical'));
        $sha = $this->gitRepository->findLatestLogicalSha($this->baseBranch);
        if ($sha !== null) {
            $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('commit.found_logical', ['sha' => $sha])}");
        }

        return $sha;
    }

    protected function commitFixupForSha(string $latestLogicalSha, bool $stageAll): int
    {
        if (! $stageAll && ! $this->hasStagedChanges()) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('commit.no_staged_changes')));

            return 1;
        }
        if ($stageAll) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.staging_conditional'));
            $this->gitRepository->stageAllChanges();
        }
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.creating_fixup', ['sha' => $latestLogicalSha]));
        $this->gitRepository->commitFixup($latestLogicalSha);
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.fixup_success', ['sha' => $latestLogicalSha]));

        return 0;
    }

    protected function commitWithJiraPrompt(bool $stageAll, bool $quiet): int
    {
        $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.note_no_logical'));

        $key = $this->gitRepository->getJiraKeyFromBranchName();
        if (! $key) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('commit.error_no_key')));

            return 1;
        }

        $issue = $this->fetchIssueForCommit($key);
        if ($issue === null) {
            return 1;
        }

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

        $this->logJiraDetails($issue, $detectedType);
        $commitMessage = "{$type}" . ($scope ? "({$scope})" : '') . ": {$summary} [{$key}]";
        $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('commit.generated_message', ['message' => $commitMessage])}");

        if (! $stageAll && ! $this->hasStagedChanges()) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('commit.no_staged_changes')));

            return 1;
        }
        if ($stageAll) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.staging_conditional'));
            $this->gitRepository->stageAllChanges();
        }
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.committing_simple'));
        $this->gitRepository->commit($commitMessage);
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.success'));

        return 0;
    }

    protected function fetchIssueForCommit(string $key): ?WorkItem
    {
        try {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('commit.fetching_jira', ['key' => $key])}");

            return $this->jiraService->getIssue($key);
        } catch (ApiException $e) {
            $this->logger->errorWithDetails(
                Logger::VERBOSITY_NORMAL,
                $this->translator->trans('commit.error_not_found', ['key' => $key]),
                $e->getTechnicalDetails()
            );

            return null;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.error_not_found', ['key' => $key]));

            return null;
        }
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    protected function promptTypeScopeSummary(WorkItem $issue, string $detectedType, ?string $defaultScope): array
    {
        $scopePrompt = ! empty($issue->components)
            ? $this->translator->trans('commit.scope_auto', ['scope' => $defaultScope])
            : $this->translator->trans('commit.scope_prompt');
        $type = $this->logger->ask($this->translator->trans('commit.type_prompt', ['type' => $detectedType]), $detectedType);
        $scope = $this->logger->ask($scopePrompt, $defaultScope);
        $summary = $this->logger->ask($this->translator->trans('commit.summary_prompt'), $issue->title);

        return [$type, $scope, $summary];
    }

    protected function logJiraDetails(WorkItem $issue, string $detectedType): void
    {
        $this->logger->jiraWriteln(Logger::VERBOSITY_VERY_VERBOSE, "  {$this->translator->trans('commit.jira_details')}");
        $this->logger->jiraWriteln(Logger::VERBOSITY_VERY_VERBOSE, "    {$this->translator->trans('commit.jira_title', ['title' => $issue->title])}");
        $this->logger->jiraWriteln(Logger::VERBOSITY_VERY_VERBOSE, "    {$this->translator->trans('commit.jira_type', ['type' => $issue->issueType, 'commit_type' => $detectedType])}");
        if (! empty($issue->components)) {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERY_VERBOSE, "    {$this->translator->trans('commit.jira_components', ['components' => implode(', ', $issue->components)])}");
        }
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
}
