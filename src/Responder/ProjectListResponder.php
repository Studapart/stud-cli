<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ProjectListResponse;
use App\Service\TranslationService;
use App\View\Column;
use App\View\TableViewConfig;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectListResponder
{
    private readonly TableViewConfig $viewConfig;

    public function __construct(
        private readonly TranslationService $translator
    ) {
        $this->viewConfig = new TableViewConfig([
            new Column('key', 'table.key', fn ($item) => $item->key),
            new Column('name', 'table.name', fn ($item) => $item->name),
        ], $this->translator);
    }

    public function respond(SymfonyStyle $io, ProjectListResponse $response): int
    {
        $io->section($this->translator->trans('project.list.section'));

        if (! $response->isSuccess()) {
            $io->error($this->translator->trans('project.list.error_fetch', ['error' => $response->getError() ?? 'Unknown error']));

            return 1;
        }

        if (empty($response->projects)) {
            $io->note($this->translator->trans('project.list.no_projects'));

            return 0;
        }

        $this->viewConfig->render($response->projects, $io);

        return 0;
    }
}
