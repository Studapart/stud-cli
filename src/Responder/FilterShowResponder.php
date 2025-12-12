<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\FilterShowResponse;
use App\Service\TranslationService;
use App\View\Column;
use App\View\TableViewConfig;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterShowResponder
{
    private readonly TableViewConfig $viewConfig;

    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly TranslationService $translator,
        private readonly array $jiraConfig
    ) {
        $this->viewConfig = new TableViewConfig([
            new Column('key', 'table.key', fn ($item) => $item->key),
            new Column('status', 'table.status', fn ($item) => $item->status),
            new Column('priority', 'table.priority', fn ($item) => $item->priority ?? '', 'priority'),
            new Column('title', 'table.description', fn ($item) => $item->title),
            new Column('jiraUrl', 'table.jira_url', fn ($item, array $context) => $context['jiraConfig']['JIRA_URL'] . '/browse/' . $item->key),
        ], $this->translator);
    }

    public function respond(SymfonyStyle $io, FilterShowResponse $response): int
    {
        $io->section($this->translator->trans('filter.show.section', ['filterName' => $response->filterName]));

        $jql = 'filter = "' . $response->filterName . '"';
        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>{$this->translator->trans('filter.show.jql_query', ['jql' => $jql])}</>");
        }

        if (! $response->isSuccess()) {
            $io->error($this->translator->trans('filter.show.error_fetch', ['error' => $response->getError() ?? 'Unknown error']));

            return 1;
        }

        if (empty($response->issues)) {
            $io->note($this->translator->trans('filter.show.no_results', ['filterName' => $response->filterName]));

            return 0;
        }

        $this->viewConfig->render($response->issues, $io, ['jiraConfig' => $this->jiraConfig]);

        return 0;
    }
}
