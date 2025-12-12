<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ProjectListResponse;
use App\Service\ColorHelper;
use App\Service\TranslationService;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectListResponder
{
    public function __construct(
        private readonly TranslationService $translator,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function respond(SymfonyStyle $io, ProjectListResponse $response): void
    {
        // Register color styles before rendering
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        $sectionTitle = $this->translator->trans('project.list.section');
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        if (empty($response->projects)) {
            $io->note($this->translator->trans('project.list.no_projects'));

            return;
        }

        $viewConfig = new PageViewConfig([
            new Section(
                '', // Section already created by responder
                [
                    new TableBlock([
                        new Column('key', 'table.key', fn ($item) => $item->key),
                        new Column('name', 'table.name', fn ($item) => $item->name),
                    ]),
                ]
            ),
        ], $this->translator, $this->colorHelper);

        $viewConfig->render($response->projects, $io);
    }
}
