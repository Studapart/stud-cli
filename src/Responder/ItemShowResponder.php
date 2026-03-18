<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ItemShowResponse;
use App\Service\DescriptionFormatter;
use App\Service\DtoSerializer;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Content;
use App\View\DefinitionItem;
use App\View\PageViewConfig;
use App\View\Section;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemShowResponder
{
    private readonly DtoSerializer $serializer;

    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly array $jiraConfig,
        private readonly Logger $logger,
        private readonly ?DescriptionFormatter $descriptionFormatter = null,
    ) {
        $this->serializer = new DtoSerializer();
    }

    public function respond(SymfonyStyle $io, ItemShowResponse $response, string $key, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        $key = strtoupper($key);
        $this->helper->initSection($this->logger, 'item.show.section', ['key' => $key]);

        if (! $response->isSuccess()) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('item.show.error_not_found', ['key' => $key]));

            return null;
        }

        $this->helper->verboseComment($this->logger, 'item.show.fetching', ['key' => $key]);

        $issue = $response->issue;
        if ($issue === null) {
            return null;
        }

        $context = [
            'jiraConfig' => $this->jiraConfig,
            'translator' => $this->helper->translator,
        ];

        $sections = $this->buildSections($issue->description, $context);
        $viewConfig = new PageViewConfig($sections, $this->helper->translator, $this->helper->colorHelper);
        $viewConfig->render([$issue], $this->logger, $context);

        return null;
    }

    protected function respondJson(ItemShowResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess() || $response->issue === null) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
        }

        return new AgentJsonResponse(true, data: [
            'issue' => $this->serializer->serialize($response->issue),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @return Section[]
     */
    protected function buildSections(string $description, array $context): array
    {
        $sections = [$this->buildMainDefinitionSection()];

        if (trim($description) !== '') {
            $sections = array_merge($sections, $this->buildDescriptionSections($description));
        }

        return $sections;
    }

    protected function buildMainDefinitionSection(): Section
    {
        return new Section(
            '',
            [
                new DefinitionItem('item.show.label_key', fn ($dto) => $dto->key),
                new DefinitionItem('item.show.label_title', fn ($dto) => $dto->title),
                new DefinitionItem('item.show.label_status', fn ($dto) => $dto->status),
                new DefinitionItem('item.show.label_assignee', fn ($dto) => $dto->assignee),
                new DefinitionItem('item.show.label_type', fn ($dto) => $dto->issueType),
                new DefinitionItem(
                    'item.show.label_labels',
                    fn ($dto, $ctx) => ! empty($dto->labels)
                        ? implode(', ', $dto->labels)
                        : $ctx['translator']->trans('item.show.label_none')
                ),
                new DefinitionItem(
                    'item.show.label_link',
                    fn ($dto, $ctx) => $ctx['jiraConfig']['JIRA_URL'] . '/browse/' . $dto->key
                ),
            ]
        );
    }

    /**
     * @return Section[]
     */
    protected function buildDescriptionSections(string $description): array
    {
        $formatter = $this->descriptionFormatter ?? new DescriptionFormatter($this->helper->translator);
        $parsedSections = $formatter->parseSections($description);
        $sections = [];

        foreach ($parsedSections as $parsed) {
            $contentLines = $parsed['contentLines'];
            $title = $parsed['title'];

            if (empty($contentLines)) {
                $sections[] = new Section($title, []);

                continue;
            }

            $formatted = $formatter->formatContentForDisplay($contentLines);
            $contentItems = [];

            foreach ($formatted['lists'] as $list) {
                $contentItems[] = new Content(fn (mixed $dto, array $ctx) => $list, 'listing');
            }
            foreach ($formatted['text'] as $text) {
                $contentItems[] = new Content(fn (mixed $dto, array $ctx) => $text, 'text');
            }

            $sections[] = new Section($title, $contentItems);
        }

        return $sections;
    }
}
