<?php

namespace App\Items;

use App\Jira\JiraService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListHandler
{
    public function __construct(private readonly JiraService $jiraService)
    {
    }

    public function handle(SymfonyStyle $io, bool $all, ?string $project): int
    {
        $io->section('Fetching Jira Items');

        $jqlParts = [];
        if (!$all) {
            $jqlParts[] = 'assignee = currentUser()';
        }
        $jqlParts[] = "status in ('To Do', 'In Progress')";
        if ($project) {
            $jqlParts[] = 'project = ' . strtoupper($project);
        }

        $jql = implode(' AND ', $jqlParts) . ' ORDER BY updated DESC';

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>JQL Query: {$jql}</>");
        }

        try {
            $issues = $this->jiraService->searchIssues($jql);
        } catch (\Exception $e) {
            $io->error('Failed to fetch items: ' . $e->getMessage());
            return 1;
        }

        if (empty($issues)) {
            $io->note('No items found matching your criteria.');
            return 0;
        }

        $table = array_map(fn (\App\DTO\WorkItem $issue) => [$issue->key, $issue->status, $issue->title], $issues);
        $io->table(['Key', 'Status', 'Summary'], $table);
        
        return 0;
    }
}
