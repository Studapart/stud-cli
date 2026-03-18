<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\SearchResponse;
use App\Service\DtoSerializer;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class SearchResponder
{
    private readonly DtoSerializer $serializer;

    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly array $jiraConfig,
        private readonly Logger $logger,
        ?DtoSerializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new DtoSerializer();
    }

    public function respond(SymfonyStyle $io, SearchResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        $this->helper->initSection($this->logger, 'search.section');
        $this->helper->verboseComment($this->logger, 'search.jql_query', ['jql' => $response->jql]);

        if (empty($response->issues)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('search.no_results'));

            return null;
        }

        $viewConfig = new PageViewConfig([
            new Section(
                '',
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
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($response->issues, $this->logger, ['jiraConfig' => $this->jiraConfig]);

        return null;
    }

    protected function respondJson(SearchResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
        }

        return new AgentJsonResponse(true, data: [
            'issues' => $this->serializer->serializeList($response->issues),
            'jql' => $response->jql,
        ]);
    }
}
