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
    private readonly IssueListJsonSerializer $issueSerializer;

    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly array $jiraConfig,
        private readonly Logger $logger,
        ?IssueListJsonSerializer $issueSerializer = null,
    ) {
        $this->issueSerializer = $issueSerializer ?? new IssueListJsonSerializer();
    }

    public function respond(SymfonyStyle $io, ItemListResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        $this->helper->initSection($this->logger, 'item.list.section');

        if ($this->shouldShowJqlComment($response)) {
            $jql = JiraAssignedActiveJqlBuilder::build($response->project, ! $response->all);
            $this->logger->comment(Logger::VERBOSITY_VERBOSE, '  ' . $this->helper->formatComment("JQL Query: {$jql}"));
        }

        if (empty($response->issues)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('item.list.no_items'));

            return null;
        }

        $columns = [
            new Column('key', 'table.key', fn ($item) => $item->key),
            new Column('status', 'table.status', fn ($item) => $item->status),
            new Column('title', 'table.summary', fn ($item) => $item->title),
        ];
        if ($response->multiProvider) {
            $columns[] = new Column(
                'provider',
                'table.provider',
                fn ($item) => $this->providerLabelForIssue($response, $item->key),
            );
        }

        $viewConfig = new PageViewConfig([
            new Section('', [new TableBlock($columns)]),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($response->issues, $this->logger);

        return null;
    }

    protected function shouldShowJqlComment(ItemListResponse $response): bool
    {
        if ($response->multiProvider) {
            return false;
        }

        if ($response->issueProviders !== []) {
            return $response->issueProviders[0] === 'jira';
        }

        return true;
    }

    protected function providerLabelForIssue(ItemListResponse $response, string $issueKey): string
    {
        foreach ($response->issues as $index => $issue) {
            if ($issue->key === $issueKey) {
                return $response->issueProviders[$index] ?? '';
            }
        }

        return '';
    }

    protected function respondJson(ItemListResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(
                false,
                error: $this->helper->translator->renderForAgentText($response->getErrorMessage() ?? 'Unknown error'),
                diagnostics: $response->hasDiagnostics() ? $response->diagnosticsPayload() : [],
            );
        }

        $jiraBaseUrl = (string) ($this->jiraConfig['JIRA_URL'] ?? '');

        return new AgentJsonResponse(true, data: [
            'issues' => $this->issueSerializer->serializeList(
                $response->issues,
                $jiraBaseUrl,
                issueProviders: $response->issueProviders,
                includeProvider: $response->multiProvider,
            ),
            'all' => $response->all,
            'project' => $response->project,
        ], diagnostics: $response->hasDiagnostics() ? $response->diagnosticsPayload() : []);
    }
}
