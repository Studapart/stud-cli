<?php

declare(strict_types=1);

namespace App\Handler;

use App\Exception\ApiException;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class StatusHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('status.section'));
        $key = $this->gitRepository->getJiraKeyFromBranchName();
        $branch = $this->gitRepository->getCurrentBranchName();

        // Jira Status
        if ($key) {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('status.fetching', ['key' => $key])}");

            try {
                $issue = $this->jiraService->getIssue($key);
                $statusText = $this->translator->trans('status.jira_status', ['status' => $issue->status, 'key' => $issue->key, 'title' => $issue->title]);
                $this->logger->writeln(Logger::VERBOSITY_NORMAL, "Jira:   <fg=yellow>[{$issue->status}]</> {$issue->key}: {$issue->title}");
            } catch (ApiException $e) {
                $this->logger->writeln(Logger::VERBOSITY_NORMAL, "Jira:   <fg=red>{$this->translator->trans('status.jira_error', ['error' => $e->getMessage()])}</>");
                $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
            } catch (\Exception $e) {
                $this->logger->writeln(Logger::VERBOSITY_NORMAL, "Jira:   <fg=red>{$this->translator->trans('status.jira_error', ['error' => $e->getMessage()])}</>");
            }
        } else {
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, "Jira:   <fg=gray>{$this->translator->trans('status.jira_no_key')}</>");
        }

        // Git Status
        $gitBranchText = $this->translator->trans('status.git_branch', ['branch' => $branch]);
        $this->logger->writeln(Logger::VERBOSITY_NORMAL, "Git:    " . $gitBranchText);

        // Local Status
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        $changeCount = count(array_filter(explode("\n", $gitStatus)));

        if ($changeCount > 0) {
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, "Local:  {$this->translator->trans('status.local_changes', ['count' => $changeCount])}");
        } else {
            $this->logger->writeln(Logger::VERBOSITY_NORMAL, "Local:  <fg=green>{$this->translator->trans('status.local_clean')}</>");
        }

        return 0;
    }
}
