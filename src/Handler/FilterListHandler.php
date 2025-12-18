<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\Filter;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterListHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io): void
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('filter.list.section'));

        try {
            $filters = $this->jiraService->getFilters();
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('filter.list.error_fetch', ['error' => $e->getMessage()]));

            return;
        }

        if (empty($filters)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('filter.list.no_filters'));

            return;
        }

        $this->sortFiltersByName($filters);

        $table = array_map(fn (Filter $filter) => [
            $filter->name,
            $filter->description ?? '',
        ], $filters);
        $this->logger->table(Logger::VERBOSITY_NORMAL, [
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
