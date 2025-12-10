<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\WorkItem;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterShowHandler
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly array $jiraConfig,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io, string $filterName): int
    {
        $io->section($this->translator->trans('filter.show.section', ['filterName' => $filterName]));

        $jql = 'filter = "' . $filterName . '"';

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>{$this->translator->trans('filter.show.jql_query', ['jql' => $jql])}</>");
        }

        try {
            $issues = $this->jiraService->searchIssues($jql);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('filter.show.error_fetch', ['error' => $e->getMessage()]));

            return 1;
        }

        if (empty($issues)) {
            $io->note($this->translator->trans('filter.show.no_results', ['filterName' => $filterName]));

            return 0;
        }

        $showPriority = $this->hasAnyPriority($issues);
        $table = $this->buildTableRows($issues, $showPriority);

        $headers = [
            $this->translator->trans('table.key'),
            $this->translator->trans('table.status'),
        ];

        if ($showPriority) {
            $headers[] = $this->translator->trans('table.priority');
        }

        $headers[] = $this->translator->trans('table.description');
        $headers[] = $this->translator->trans('table.jira_url');

        $io->table($headers, $table);

        return 0;
    }

    /**
     * Checks if any issue in the array has a non-null priority.
     *
     * @param WorkItem[] $issues
     */
    protected function hasAnyPriority(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->priority !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds table rows from issues, conditionally including priority column.
     *
     * @param WorkItem[] $issues
     * @return array<int, array<string>>
     */
    protected function buildTableRows(array $issues, bool $showPriority): array
    {
        return array_map(function (WorkItem $issue) use ($showPriority) {
            $row = [
                $issue->key,
                $issue->status,
            ];

            if ($showPriority) {
                $row[] = $issue->priority ?? '';
            }

            $row[] = $issue->title;
            $row[] = $this->jiraConfig['JIRA_URL'] . '/browse/' . $issue->key;

            return $row;
        }, $issues);
    }
}
