<?php

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\JiraService;
use Symfony\Component\Console\Style\SymfonyStyle;

class StatusHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $io->section('Current Status');
        $key = $this->gitRepository->getJiraKeyFromBranchName();
        $branch = $this->gitRepository->getCurrentBranchName();

        // Jira Status
        if ($key) {
            if ($io->isVerbose()) {
                $io->writeln("  <fg=gray>Fetching status for Jira issue: {$key}</>");
            }
            try {
                $issue = $this->jiraService->getIssue($key);
                $io->writeln("Jira:   <fg=yellow>[{$issue->status}]</> {$issue->key}: {$issue->title}");
            } catch (\Exception $e) {
                $io->writeln("Jira:   <fg=red>Could not fetch Jira issue details: {$e->getMessage()}</>");
            }
        } else {
            $io->writeln("Jira:   <fg=gray>No Jira key found in branch name.</>");
        }

        // Git Status
        $io->writeln("Git:    On branch <fg=cyan>'{$branch}'</>");

        // Local Status
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        $changeCount = count(array_filter(explode("\n", $gitStatus)));

        if ($changeCount > 0) {
            $io->writeln("Local:  You have <fg=red>{$changeCount} uncommitted changes.</>");
        } else {
            $io->writeln("Local:  <fg=green>Working directory is clean.</>");
        }
        
        return 0;
    }
}
