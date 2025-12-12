<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ItemListResponse;
use App\Service\ColorHelper;
use App\Service\TranslationService;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemListResponder
{
    public function __construct(
        private readonly TranslationService $translator,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function respond(SymfonyStyle $io, ItemListResponse $response): void
    {
        // Register color styles before rendering
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        $sectionTitle = $this->translator->trans('item.list.section');
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        $jqlParts = [];
        if (! $response->all) {
            $jqlParts[] = 'assignee = currentUser()';
        }
        $jqlParts[] = "statusCategory in ('To Do', 'In Progress')";
        if ($response->project) {
            $jqlParts[] = 'project = ' . strtoupper($response->project);
        }
        $jql = implode(' AND ', $jqlParts) . ' ORDER BY updated DESC';

        if ($io->isVerbose()) {
            $jqlMessage = "JQL Query: {$jql}";
            if ($this->colorHelper !== null) {
                $jqlMessage = $this->colorHelper->format('comment', $jqlMessage);
            } else {
                $jqlMessage = "<fg=gray>{$jqlMessage}</>";
            }
            $io->writeln("  {$jqlMessage}");
        }

        if (empty($response->issues)) {
            $io->note($this->translator->trans('item.list.no_items'));

            return;
        }

        $viewConfig = new PageViewConfig([
            new Section(
                '', // Section already created by responder
                [
                    new TableBlock([
                        new Column('key', 'table.key', fn ($item) => $item->key),
                        new Column('status', 'table.status', fn ($item) => $item->status),
                        new Column('title', 'table.summary', fn ($item) => $item->title),
                    ]),
                ]
            ),
        ], $this->translator, $this->colorHelper);

        $viewConfig->render($response->issues, $io);
    }
}
