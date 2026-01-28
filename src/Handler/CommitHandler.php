<?php

declare(strict_types=1);

namespace App\Handler;

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

    public function handle(SymfonyStyle $io, bool $isNew, ?string $message): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.section'));

        // Check for clean working tree before proceeding
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (empty(trim($gitStatus))) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.note_clean_working_tree'));

            return 0;
        }

        // If a message is provided via the -m flag, use it directly.
        if (! empty($message)) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.staging'));
            $this->gitRepository->stageAllChanges();

            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.committing'));
            $this->gitRepository->commit($message);

            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.success'));

            return 0;
        }

        // 1. Auto-Fixup Strategy: Find the latest logical commit
        $latestLogicalSha = null;
        if (! $isNew) {
            $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, '  ' . $this->translator->trans('commit.checking_logical'));
            $latestLogicalSha = $this->gitRepository->findLatestLogicalSha($this->baseBranch);
            if ($latestLogicalSha) {
                $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('commit.found_logical', ['sha' => $latestLogicalSha])}");
            }
        }

        // 2. If a logical commit is found and --new is not used, create a fixup commit
        if ($latestLogicalSha) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.staging'));
            $this->gitRepository->stageAllChanges();

            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.creating_fixup', ['sha' => $latestLogicalSha]));
            $this->gitRepository->commitFixup($latestLogicalSha);

            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.fixup_success', ['sha' => $latestLogicalSha]));

            return 0;
        }

        // 3. If no logical commit is found OR --new is used, run the interactive prompter
        $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.note_no_logical'));

        $key = $this->gitRepository->getJiraKeyFromBranchName();
        if (! $key) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('commit.error_no_key')));

            return 1;
        }

        try {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('commit.fetching_jira', ['key' => $key])}");
            // The getIssue method now fetches components as well
            $issue = $this->jiraService->getIssue($key);
        } catch (ApiException $e) {
            $this->logger->errorWithDetails(
                Logger::VERBOSITY_NORMAL,
                $this->translator->trans('commit.error_not_found', ['key' => $key]),
                $e->getTechnicalDetails()
            );

            return 1;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.error_not_found', ['key' => $key]));

            return 1;
        }

        $detectedType = $this->getCommitTypeFromIssueType($issue->issueType);
        $detectedSummary = $issue->title;

        $this->logger->jiraWriteln(Logger::VERBOSITY_VERY_VERBOSE, "  {$this->translator->trans('commit.jira_details')}");
        $this->logger->jiraWriteln(Logger::VERBOSITY_VERY_VERBOSE, "    {$this->translator->trans('commit.jira_title', ['title' => $issue->title])}");
        $this->logger->jiraWriteln(Logger::VERBOSITY_VERY_VERBOSE, "    {$this->translator->trans('commit.jira_type', ['type' => $issue->issueType, 'commit_type' => $detectedType])}");
        if (! empty($issue->components)) {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERY_VERBOSE, "    {$this->translator->trans('commit.jira_components', ['components' => implode(', ', $issue->components)])}");
        }

        // 4. Upgraded Interactive Prompter with Scope Inference
        // IMPORTANT: Prompts are translated, but commit message itself stays in English
        $scopePrompt = $this->translator->trans('commit.scope_prompt');
        $defaultScope = null;
        if (! empty($issue->components)) {
            $defaultScope = $issue->components[0]; // Use the first component name
            $scopePrompt = $this->translator->trans('commit.scope_auto', ['scope' => $defaultScope]);
        }

        $type = $this->logger->ask($this->translator->trans('commit.type_prompt', ['type' => $detectedType]), $detectedType);
        $scope = $this->logger->ask($scopePrompt, $defaultScope);
        $summary = $this->logger->ask($this->translator->trans('commit.summary_prompt'), $detectedSummary);

        // 5. Assemble commit message according to the new template
        // CRITICAL: Commit message MUST remain in English regardless of user's language
        $commitMessage = "{$type}" . ($scope ? "({$scope})" : "") . ": {$summary} [{$key}]";

        $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('commit.generated_message', ['message' => $commitMessage])}");

        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.staging'));
        $this->gitRepository->stageAllChanges();

        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.committing_simple'));
        $this->gitRepository->commit($commitMessage);

        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('commit.success'));

        return 0;
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
}
