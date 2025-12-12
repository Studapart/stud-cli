<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\FilterShowResponse;
use App\Service\ColorHelper;
use App\Service\TranslationService;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterShowResponder
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly TranslationService $translator,
        private readonly array $jiraConfig,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function respond(SymfonyStyle $io, FilterShowResponse $response): void
    {
        // Register color styles before rendering
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        $sectionTitle = $this->translator->trans('filter.show.section', ['filterName' => $response->filterName]);
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        $jql = 'filter = "' . $response->filterName . '"';
        if ($io->isVerbose()) {
            $jqlMessage = $this->translator->trans('filter.show.jql_query', ['jql' => $jql]);
            if ($this->colorHelper !== null) {
                $jqlMessage = $this->colorHelper->format('comment', $jqlMessage);
            } else {
                $jqlMessage = "<fg=gray>{$jqlMessage}</>";
            }
            $io->writeln("  {$jqlMessage}");
        }

        if (empty($response->issues)) {
            $io->note($this->translator->trans('filter.show.no_results', ['filterName' => $response->filterName]));

            return;
        }

        $viewConfig = new PageViewConfig([
            new Section(
                '', // Section already created by responder
                [
                    new TableBlock([
                        new Column('key', 'table.key', fn ($item) => $item->key),
                        new Column('status', 'table.status', fn ($item) => $item->status),
                        new Column('priority', 'table.priority', fn ($item) => $item->priority ?? '', 'priority'),
                        new Column('title', 'table.description', fn ($item) => $item->title),
                        new Column('jiraUrl', 'table.jira_url', fn ($item, array $context) => $context['jiraConfig']['JIRA_URL'] . '/browse/' . $item->key),
                    ]),
                ]
            ),
        ], $this->translator, $this->colorHelper);

        $viewConfig->render($response->issues, $io, ['jiraConfig' => $this->jiraConfig]);
    }
}
