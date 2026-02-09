<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\BranchListResponse;
use App\Service\ColorHelper;
use App\Service\TranslationService;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchListResponder
{
    public function __construct(
        private readonly TranslationService $translator,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function respond(SymfonyStyle $io, BranchListResponse $response): void
    {
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        $sectionTitle = $this->translator->trans('branches.list.section');
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        if ($io->isVerbose()) {
            $fetchingLocal = $this->translator->trans('branches.list.fetching_local');
            if ($this->colorHelper !== null) {
                $fetchingLocal = $this->colorHelper->format('comment', $fetchingLocal);
            } else {
                $fetchingLocal = "<fg=gray>{$fetchingLocal}</>";
            }
            $io->writeln("  {$fetchingLocal}");
        }

        if (empty($response->rows)) {
            $io->writeln($this->translator->trans('branches.list.no_branches'));

            return;
        }

        if ($io->isVerbose()) {
            $fetchingRemote = $this->translator->trans('branches.list.fetching_remote', ['count' => count($response->rows)]);
            if ($this->colorHelper !== null) {
                $fetchingRemote = $this->colorHelper->format('comment', $fetchingRemote);
            } else {
                $fetchingRemote = "<fg=gray>{$fetchingRemote}</>";
            }
            $io->writeln("  {$fetchingRemote}");
            $noteOrigin = $this->translator->trans('branches.list.note_origin');
            if ($this->colorHelper !== null) {
                $noteOrigin = $this->colorHelper->format('comment', $noteOrigin);
            } else {
                $noteOrigin = "<fg=gray>{$noteOrigin}</>";
            }
            $io->note("  {$noteOrigin}");
        }

        $viewConfig = new PageViewConfig([
            new Section(
                '',
                [
                    new TableBlock([
                        new Column('branch', 'branches.list.column.branch', fn ($row) => $row->branch),
                        new Column('status', 'branches.list.column.status', fn ($row) => $row->status),
                        new Column('remote', 'branches.list.column.remote', fn ($row) => $row->remote),
                        new Column('pr', 'branches.list.column.pr', fn ($row) => $row->pr),
                    ]),
                ]
            ),
        ], $this->translator, $this->colorHelper);

        $viewConfig->render($response->rows, $io);
    }
}
