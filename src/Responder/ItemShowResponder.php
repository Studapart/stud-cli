<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ItemShowResponse;
use App\Service\ColorHelper;
use App\Service\DescriptionFormatter;
use App\Service\TranslationService;
use App\View\Content;
use App\View\DefinitionItem;
use App\View\PageViewConfig;
use App\View\Section;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemShowResponder
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly TranslationService $translator,
        private readonly array $jiraConfig,
        private readonly ?DescriptionFormatter $descriptionFormatter = null,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function respond(SymfonyStyle $io, ItemShowResponse $response, string $key): void
    {
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        $key = strtoupper($key);
        $sectionTitle = $this->translator->trans('item.show.section', ['key' => $key]);
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        if (! $response->isSuccess()) {
            $io->error($this->translator->trans('item.show.error_not_found', ['key' => $key]));

            return;
        }

        if ($io->isVerbose()) {
            $fetchingMessage = $this->translator->trans('item.show.fetching', ['key' => $key]);
            if ($this->colorHelper !== null) {
                $fetchingMessage = $this->colorHelper->format('comment', $fetchingMessage);
            } else {
                $fetchingMessage = "<fg=gray>{$fetchingMessage}</>";
            }
            $io->writeln("  {$fetchingMessage}");
        }

        $issue = $response->issue;
        if ($issue === null) {
            return;
        }

        $context = [
            'jiraConfig' => $this->jiraConfig,
            'translator' => $this->translator,
        ];

        $sections = $this->buildSections($issue->description, $context);
        $viewConfig = new PageViewConfig($sections, $this->translator, $this->colorHelper);
        $viewConfig->render([$issue], $io, $context);
    }

    /**
     * Builds PageViewConfig sections: main definition list plus description sections.
     *
     * @param array<string, mixed> $context
     * @return Section[]
     */
    protected function buildSections(string $description, array $context): array
    {
        $mainSection = new Section(
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

        $sections = [$mainSection];

        if (trim($description) !== '') {
            $formatter = $this->descriptionFormatter ?? new DescriptionFormatter($this->translator);
            $parsedSections = $formatter->parseSections($description);

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
        }

        return $sections;
    }
}
