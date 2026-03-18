<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ItemListResponse;
use App\Service\DtoSerializer;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemListResponder
{
    private readonly DtoSerializer $serializer;

    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        ?DtoSerializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new DtoSerializer();
    }

    public function respond(SymfonyStyle $io, ItemListResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        $this->helper->initSection($this->logger, 'item.list.section');

        $jql = $this->buildJql($response);
        $this->logger->comment(Logger::VERBOSITY_VERBOSE, '  ' . $this->helper->formatComment("JQL Query: {$jql}"));

        if (empty($response->issues)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('item.list.no_items'));

            return null;
        }

        $viewConfig = new PageViewConfig([
            new Section(
                '',
                [
                    new TableBlock([
                        new Column('key', 'table.key', fn ($item) => $item->key),
                        new Column('status', 'table.status', fn ($item) => $item->status),
                        new Column('title', 'table.summary', fn ($item) => $item->title),
                    ]),
                ]
            ),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($response->issues, $this->logger);

        return null;
    }

    protected function buildJql(ItemListResponse $response): string
    {
        $jqlParts = [];
        if (! $response->all) {
            $jqlParts[] = 'assignee = currentUser()';
        }
        $jqlParts[] = "statusCategory in ('To Do', 'In Progress')";
        if ($response->project) {
            $jqlParts[] = 'project = ' . strtoupper($response->project);
        }

        return implode(' AND ', $jqlParts) . ' ORDER BY updated DESC';
    }

    protected function respondJson(ItemListResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
        }

        return new AgentJsonResponse(true, data: [
            'issues' => $this->serializer->serializeList($response->issues),
            'all' => $response->all,
            'project' => $response->project,
        ]);
    }
}
