<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ProjectListResponse;
use App\Service\DtoSerializer;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectListResponder
{
    private readonly DtoSerializer $serializer;

    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        ?DtoSerializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new DtoSerializer();
    }

    public function respond(SymfonyStyle $io, ProjectListResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        $this->helper->initSection($this->logger, 'project.list.section');

        if (empty($response->projects)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('project.list.no_projects'));

            return null;
        }

        $viewConfig = new PageViewConfig([
            new Section(
                '',
                [
                    new TableBlock([
                        new Column('key', 'table.key', fn ($item) => $item->key),
                        new Column('name', 'table.name', fn ($item) => $item->name),
                    ]),
                ]
            ),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($response->projects, $this->logger);

        return null;
    }

    protected function respondJson(ProjectListResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
        }

        return new AgentJsonResponse(true, data: [
            'projects' => $this->serializer->serializeList($response->projects),
        ]);
    }
}
