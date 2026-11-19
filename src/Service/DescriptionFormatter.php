<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Formats Jira issue descriptions by parsing sections, sanitizing content, and formatting for display.
 */
class DescriptionFormatter
{
    /**
     * Sanitizes content by collapsing multiple consecutive newlines into single newlines.
     * Replaces 2+ consecutive newlines (\n{2,}) with a single newline.
     *
     * @param array<string> $lines
     * @return array<string>
     */
    protected function sanitizeContent(array $lines): array
    {
        $sanitized = [];
        $prevEmpty = false;

        foreach ($lines as $line) {
            $isEmpty = trim($line) === '';
            if ($isEmpty && $prevEmpty) {
                // Skip consecutive empty lines
                continue;
            }
            $sanitized[] = $line;
            $prevEmpty = $isEmpty;
        }

        return $sanitized;
    }

    /**
     * Parses description into sections based on dividers (3+ dashes).
     * Returns an array of sections, where each section has a title and content lines.
     *
     * Strategy: Split by sections first, then process each section's lines.
     *
     * @return array<int, array{title: string, contentLines: array<string>}>
     */
    public function parseSections(string $description, string $defaultTitle): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }
        $lines = explode("\n", $description);
        $sectionParts = $this->splitDescriptionByDividers($lines);
        if ($sectionParts === []) {
            $sectionParts = [$lines];
        }
        $sections = [];
        foreach ($sectionParts as $sectionLines) {
            $sections[] = $this->processOneSectionToTitleAndContent($sectionLines, $defaultTitle);
        }

        return $sections;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array<int, string>>
     */
    protected function splitDescriptionByDividers(array $lines): array
    {
        $sectionParts = [];
        $currentSection = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^-{3,}$/', $trimmed)) {
                if ($currentSection !== []) {
                    $sectionParts[] = $currentSection;
                }
                $currentSection = [];
            } else {
                $currentSection[] = $line;
            }
        }
        if ($currentSection !== []) {
            $sectionParts[] = $currentSection;
        }

        return $sectionParts;
    }

    /**
     * @param array<int, string> $sectionLines
     * @return array{title: string, contentLines: array<string>}
     */
    protected function processOneSectionToTitleAndContent(array $sectionLines, string $defaultTitle): array
    {
        $title = '';
        $contentLines = [];
        $titleFound = false;
        foreach ($sectionLines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                if (! $titleFound) {
                    $title = $trimmed;
                    $titleFound = true;
                } else {
                    $contentLines[] = $line;
                }
            } elseif ($titleFound) {
                $contentLines[] = '';
            }
        }
        if ($title === '') {
            $title = $defaultTitle;
        }
        if ($contentLines === [] && $title !== '') {
            $isSectionHeader = preg_match('/^[^:]+:\s*.+$/', $title)
                || preg_match('/^(Title|User Story|Description & Implementation Logic|Acceptance Criteria)(\s*:)?$/i', $title);
            if (! $isSectionHeader) {
                $contentLines = [$title];
                $title = $defaultTitle;
            }
        }

        return [
            'title' => $title,
            'contentLines' => $this->sanitizeContent($contentLines),
        ];
    }

    /**
     * Formats content lines for display, handling checkbox lists specially.
     * Returns formatted content as an array with 'lists' and 'text' keys.
     *
     * @param array<string> $lines
     * @return array{lists: array<array<string>>, text: array<array<string>>}
     */
    public function formatContentForDisplay(array $lines): array
    {
        $builder = new ChecklistDisplayBuilder();

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^\[\s*[xX]?\s*\]\s*(.+)$/', $trimmed, $matches)) {
                $builder->startCheckboxItem(trim($matches[1]));
            } else {
                $builder->addNonCheckboxLine($trimmed);
            }
        }

        return $builder->build();
    }
}
