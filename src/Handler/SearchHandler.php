<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\WorkItem;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class SearchHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io, string $jql): int
    {
        $io->section($this->translator->trans('search.section'));
        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>{$this->translator->trans('search.jql_query', ['jql' => $jql])}</>");
        }

        try {
            $issues = $this->jiraService->searchIssues($jql);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('search.error_search', ['error' => $e->getMessage()]));

            return 1;
        }

        if (empty($issues)) {
            $io->note($this->translator->trans('search.no_results'));

            return 0;
        }

        $table = array_map(fn (WorkItem $issue) => [$issue->key, $issue->status, $issue->title], $issues);
        $io->table([
            $this->translator->trans('table.key'),
            $this->translator->trans('table.status'),
            $this->translator->trans('table.summary'),
        ], $table);

        return 0;
    }
}
