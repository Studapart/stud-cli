<?php

namespace App\Handler;

use App\Service\JiraService;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\TableSeparator;

class ItemShowHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly array $jiraConfig
    ) {
    }

    public function handle(SymfonyStyle $io, string $key): void
    {
        $key = strtoupper($key);
        $io->section("Details for issue {$key}");
        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>Fetching details for issue: {$key}</>");
        }
        try {
            $issue = $this->jiraService->getIssue($key, true);
        } catch (\Exception $e) {
            $io->error("Could not find Jira issue with key \"{$key}\".");
            return;
        }

        $io->definitionList(
            ['Key' => $issue->key],
            ['Title' => $issue->title],
            ['Status' => $issue->status],
            ['Assignee' => $issue->assignee],
            ['Type' => $issue->issueType],
            ['Labels' => !empty($issue->labels) ? implode(', ', $issue->labels) : 'None'],
            new TableSeparator(), // separator
            ['Description' => $issue->description],
            new TableSeparator(), // separator
            ['Link' => $this->jiraConfig['JIRA_URL'] . '/browse/' . $issue->key]
        );
    }
}