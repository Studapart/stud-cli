<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\BranchListResponse;
use App\Service\DtoSerializer;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchListResponder
{
    private readonly DtoSerializer $serializer;

    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        ?DtoSerializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new DtoSerializer();
    }

    public function respond(SymfonyStyle $io, BranchListResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        $this->helper->initSection($this->logger, 'branches.list.section');
        $this->helper->verboseComment($this->logger, 'branches.list.fetching_local');

        if (empty($response->rows)) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('branches.list.no_branches'));

            return null;
        }

        $this->helper->verboseComment($this->logger, 'branches.list.fetching_remote', ['count' => count($response->rows)]);
        $this->helper->verboseNote($this->logger, 'branches.list.note_origin');

        $viewConfig = new PageViewConfig([
            new Section(
                '',
                [
                    new TableBlock([
                        new Column('branch', 'branches.list.column.branch', fn ($row) => $row->branch),
                        new Column('status', 'branches.list.column.status', fn ($row) => $row->status),
                        new Column('autoClean', 'branches.list.column.auto_clean', fn ($row) => $row->autoClean),
                        new Column('remote', 'branches.list.column.remote', fn ($row) => $row->remote),
                        new Column('pr', 'branches.list.column.pr', fn ($row) => $row->pr),
                    ]),
                ]
            ),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($response->rows, $this->logger);

        return null;
    }

    protected function respondJson(BranchListResponse $response): AgentJsonResponse
    {
        return new AgentJsonResponse(true, data: [
            'rows' => $this->serializer->serializeList($response->rows),
        ]);
    }
}
