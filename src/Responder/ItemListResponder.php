<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ItemListResponse;
use App\Service\Jira\JiraAssignedActiveJqlBuilder;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemListResponder
{
    private readonly WorkItemListJsonSerializer $issueSerializer;

    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly array $jiraConfig,
        private readonly Logger $logger,
        ?WorkItemListJsonSerializer $issueSerializer = null,
    ) {
        $this->issueSerializer = $issueSerializer ?? new WorkItemListJsonSerializer();
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
        return JiraAssignedActiveJqlBuilder::build($response->project, ! $response->all);
    }

    protected function respondJson(ItemListResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(
                false,
                error: $this->helper->translator->renderForAgentText($response->getErrorMessage() ?? 'Unknown error'),
            );
        }

        $jiraBaseUrl = (string) ($this->jiraConfig['JIRA_URL'] ?? '');

        return new AgentJsonResponse(true, data: [
            'issues' => $this->issueSerializer->serializeList($response->issues, $jiraBaseUrl),
            'all' => $response->all,
            'project' => $response->project,
        ]);
    }
}
