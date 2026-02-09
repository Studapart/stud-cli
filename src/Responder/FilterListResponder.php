<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\FilterListResponse;
use App\Service\ColorHelper;
use App\Service\TranslationService;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterListResponder
{
    public function __construct(
        private readonly TranslationService $translator,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function respond(SymfonyStyle $io, FilterListResponse $response): void
    {
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        $sectionTitle = $this->translator->trans('filter.list.section');
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        if (empty($response->filters)) {
            $io->note($this->translator->trans('filter.list.no_filters'));

            return;
        }

        $viewConfig = new PageViewConfig([
            new Section(
                '',
                [
                    new TableBlock([
                        new Column('name', 'table.name', fn ($item) => $item->name),
                        new Column('description', 'table.description', fn ($item) => $item->description ?? ''),
                    ]),
                ]
            ),
        ], $this->translator, $this->colorHelper);

        $viewConfig->render($response->filters, $io);
    }
}
