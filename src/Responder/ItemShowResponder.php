<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ItemShowResponse;
use App\Service\ColorHelper;
use App\Service\DescriptionFormatter;
use App\Service\TranslationService;
use Symfony\Component\Console\Helper\TableSeparator;
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
        // Register color styles before rendering
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

        // Display definition list with separator
        $keyLabel = $this->translator->trans('item.show.label_key');
        $titleLabel = $this->translator->trans('item.show.label_title');
        $statusLabel = $this->translator->trans('item.show.label_status');
        $assigneeLabel = $this->translator->trans('item.show.label_assignee');
        $typeLabel = $this->translator->trans('item.show.label_type');
        $labelsLabel = $this->translator->trans('item.show.label_labels');
        $linkLabel = $this->translator->trans('item.show.label_link');

        $keyValue = $issue->key;
        $titleValue = $issue->title;
        $statusValue = $issue->status;
        $assigneeValue = $issue->assignee;
        $typeValue = $issue->issueType;
        $labelsValue = ! empty($issue->labels) ? implode(', ', $issue->labels) : $this->translator->trans('item.show.label_none');
        $linkValue = $this->jiraConfig['JIRA_URL'] . '/browse/' . $issue->key;

        // Apply colors if ColorHelper is available
        if ($this->colorHelper !== null) {
            $keyLabel = $this->colorHelper->format('definition_key', $keyLabel);
            $titleLabel = $this->colorHelper->format('definition_key', $titleLabel);
            $statusLabel = $this->colorHelper->format('definition_key', $statusLabel);
            $assigneeLabel = $this->colorHelper->format('definition_key', $assigneeLabel);
            $typeLabel = $this->colorHelper->format('definition_key', $typeLabel);
            $labelsLabel = $this->colorHelper->format('definition_key', $labelsLabel);
            $linkLabel = $this->colorHelper->format('definition_key', $linkLabel);

            $keyValue = $this->colorHelper->format('definition_value', $keyValue);
            $titleValue = $this->colorHelper->format('definition_value', $titleValue);
            $statusValue = $this->colorHelper->format('definition_value', $statusValue);
            $assigneeValue = $this->colorHelper->format('definition_value', $assigneeValue);
            $typeValue = $this->colorHelper->format('definition_value', $typeValue);
            $labelsValue = $this->colorHelper->format('definition_value', $labelsValue);
            $linkValue = $this->colorHelper->format('definition_value', $linkValue);
        }

        $io->definitionList(
            [$keyLabel => $keyValue],
            [$titleLabel => $titleValue],
            [$statusLabel => $statusValue],
            [$assigneeLabel => $assigneeValue],
            [$typeLabel => $typeValue],
            [$labelsLabel => $labelsValue],
            new TableSeparator(), // separator
            [$linkLabel => $linkValue]
        );

        // Display description sections
        $this->displayDescription($io, $issue->description);
    }

    /**
     * Displays the description in dedicated sections.
     * Detects checkbox lists (lines starting with [ ]) and displays them using $io->listing().
     */
    protected function displayDescription(SymfonyStyle $io, string $description): void
    {
        if (empty(trim($description))) {
            return;
        }

        $formatter = $this->descriptionFormatter ?? new DescriptionFormatter($this->translator);
        $sections = $formatter->parseSections($description);

        foreach ($sections as $section) {
            $sectionTitle = $section['title'];
            if ($this->colorHelper !== null) {
                $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
            }
            $io->section($sectionTitle);
            if (! empty($section['contentLines'])) {
                $this->displayContent($io, $section['contentLines'], $formatter);
            }
        }
    }

    /**
     * Displays content lines, handling checkbox lists specially.
     * Uses DescriptionFormatter to format content.
     *
     * @param array<string> $lines
     */
    protected function displayContent(SymfonyStyle $io, array $lines, DescriptionFormatter $formatter): void
    {
        $formatted = $formatter->formatContentForDisplay($lines);

        // Display lists
        foreach ($formatted['lists'] as $list) {
            if ($this->colorHelper !== null) {
                $list = array_map(fn ($item) => $this->colorHelper->format('listing_item', (string) $item), $list);
            }
            $io->listing($list);
        }

        // Display text
        foreach ($formatted['text'] as $text) {
            if ($this->colorHelper !== null && is_array($text)) {
                $text = array_map(fn ($line) => $this->colorHelper->format('text_content', (string) $line), $text);
            }
            $io->text($text);
        }
    }
}
