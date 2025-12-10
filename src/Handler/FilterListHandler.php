<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\Filter;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterListHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io): void
    {
        $io->section($this->translator->trans('filter.list.section'));

        try {
            $filters = $this->jiraService->getFilters();
        } catch (\Exception $e) {
            $io->error($this->translator->trans('filter.list.error_fetch', ['error' => $e->getMessage()]));

            return;
        }

        if (empty($filters)) {
            $io->note($this->translator->trans('filter.list.no_filters'));

            return;
        }

        $this->sortFiltersByName($filters);

        $table = array_map(fn (Filter $filter) => [
            $filter->name,
            $filter->description ?? '',
        ], $filters);
        $io->table([
            $this->translator->trans('table.name'),
            $this->translator->trans('table.description'),
        ], $table);
    }

    /**
     * @param Filter[] $filters
     */
    protected function sortFiltersByName(array &$filters): void
    {
        usort($filters, fn (Filter $a, Filter $b) => strcasecmp($a->name, $b->name));
    }
}
