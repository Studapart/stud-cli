<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemListHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io, bool $all, ?string $project): int
    {
        $io->section($this->translator->trans('item.list.section'));

        $jqlParts = [];
        if (! $all) {
            $jqlParts[] = 'assignee = currentUser()';
        }
        $jqlParts[] = "statusCategory in ('To Do', 'In Progress')";
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
            $io->error($this->translator->trans('item.list.error_fetch', ['error' => $e->getMessage()]));

            return 1;
        }

        if (empty($issues)) {
            $io->note($this->translator->trans('item.list.no_items'));

            return 0;
        }

        $table = array_map(fn (\App\DTO\WorkItem $issue) => [$issue->key, $issue->status, $issue->title], $issues);
        $io->table([
            $this->translator->trans('table.key'),
            $this->translator->trans('table.status'),
            $this->translator->trans('table.summary'),
        ], $table);

        return 0;
    }
}
