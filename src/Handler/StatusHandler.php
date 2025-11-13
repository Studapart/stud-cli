<?php

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class StatusHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $io->section($this->translator->trans('status.section'));
        $key = $this->gitRepository->getJiraKeyFromBranchName();
        $branch = $this->gitRepository->getCurrentBranchName();

        // Jira Status
        if ($key) {
            if ($io->isVerbose()) {
                $io->writeln("  <fg=gray>{$this->translator->trans('status.fetching', ['key' => $key])}</>");
            }
            try {
                $issue = $this->jiraService->getIssue($key);
                $statusText = $this->translator->trans('status.jira_status', ['status' => $issue->status, 'key' => $issue->key, 'title' => $issue->title]);
                $io->writeln("Jira:   <fg=yellow>[{$issue->status}]</> {$issue->key}: {$issue->title}");
            } catch (\Exception $e) {
                $io->writeln("Jira:   <fg=red>{$this->translator->trans('status.jira_error', ['error' => $e->getMessage()])}</>");
            }
        } else {
            $io->writeln("Jira:   <fg=gray>{$this->translator->trans('status.jira_no_key')}</>");
        }

        // Git Status
        $gitBranchText = $this->translator->trans('status.git_branch', ['branch' => $branch]);
        $io->writeln("Git:    " . $gitBranchText);

        // Local Status
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        $changeCount = count(array_filter(explode("\n", $gitStatus)));

        if ($changeCount > 0) {
            $io->writeln("Local:  {$this->translator->trans('status.local_changes', ['count' => $changeCount])}");
        } else {
            $io->writeln("Local:  <fg=green>{$this->translator->trans('status.local_clean')}</>");
        }
        
        return 0;
    }
}
