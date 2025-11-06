<?php

namespace App\Handler;

use App\DTO\WorkItem;
use App\Service\JiraService;
use Symfony\Component\Console\Style\SymfonyStyle;

class SearchHandler
{
    public function __construct(
        private readonly JiraService $jiraService
    ) {
    }

    public function handle(SymfonyStyle $io, string $jql): void
    {
        $io->section('Searching Jira issues with JQL');
        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>JQL Query: {$jql}</>");
        }
        try {
            $issues = $this->jiraService->searchIssues($jql);
        } catch (\Exception $e) {
            $io->error('Failed to search for issues: ' . $e->getMessage());
            return;
        }

        if (empty($issues)) {
            $io->note('No issues found matching your JQL query.');
            return;
        }

        $table = array_map(fn (WorkItem $issue) => [$issue->key, $issue->status, $issue->title], $issues);
        $io->table(['Key', 'Status', 'Summary'], $table);
    }
}
