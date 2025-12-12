<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ItemListResponse;
use App\Service\TranslationService;
use App\View\Column;
use App\View\TableViewConfig;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemListResponder
{
    private readonly TableViewConfig $viewConfig;

    public function __construct(
        private readonly TranslationService $translator
    ) {
        $this->viewConfig = new TableViewConfig([
            new Column('key', 'table.key', fn ($item) => $item->key),
            new Column('status', 'table.status', fn ($item) => $item->status),
            new Column('title', 'table.summary', fn ($item) => $item->title),
        ], $this->translator);
    }

    public function respond(SymfonyStyle $io, ItemListResponse $response): int
    {
        $io->section($this->translator->trans('item.list.section'));

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
            $io->writeln("  <fg=gray>JQL Query: {$jql}</>");
        }

        if (! $response->isSuccess()) {
            $io->error($this->translator->trans('item.list.error_fetch', ['error' => $response->getError() ?? 'Unknown error']));

            return 1;
        }

        if (empty($response->issues)) {
            $io->note($this->translator->trans('item.list.no_items'));

            return 0;
        }

        $this->viewConfig->render($response->issues, $io);

        return 0;
    }
}
