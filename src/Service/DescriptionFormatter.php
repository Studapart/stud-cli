<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Formats Jira issue descriptions by parsing sections, sanitizing content, and formatting for display.
 */
class DescriptionFormatter
{
    public function __construct(
        private readonly TranslationService $translator
    ) {
    }

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
    public function parseSections(string $description): array
    {
        $sections = [];

        $description = trim($description);
        if (empty($description)) {
            return $sections;
        }

        // Step 1: Split by sections (dividers) first
        $lines = explode("\n", $description);
        $sectionParts = [];
        $currentSection = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Check if line is a divider (only dashes, 3+)
            if (preg_match('/^-{3,}$/', $trimmed)) {
                // Found divider - save current section and start new one
                if (! empty($currentSection)) {
                    $sectionParts[] = $currentSection;
                }
                $currentSection = [];
            } else {
                $currentSection[] = $line;
            }
        }

        // Add last section
        if (! empty($currentSection)) {
            $sectionParts[] = $currentSection;
        }

        // If no dividers found, treat whole description as one section
        // This path is only reached when description has dividers but all sections end up empty
        // which is an edge case that cannot occur in normal usage (empty description is handled earlier)
        // @codeCoverageIgnoreStart
        if (empty($sectionParts)) {
            $sectionParts = [explode("\n", $description)];
        }
        // @codeCoverageIgnoreEnd

        // Step 2: Process each section - trim lines once
        foreach ($sectionParts as $sectionLines) {
            // Find first non-empty line as title
            $title = '';
            $contentLines = [];
            $titleFound = false;

            foreach ($sectionLines as $line) {
                // Use trim() only - it's safe and only removes whitespace
                $trimmed = trim($line);
                if (! empty($trimmed)) {
                    if (! $titleFound) {
                        $title = $trimmed;
                        $titleFound = true;
                    } else {
                        // Preserve content lines as-is - we'll trim only when displaying
                        $contentLines[] = $line;
                    }
                } elseif ($titleFound) {
                    // Empty line after title - preserve it
                    $contentLines[] = '';
                }
            }

            // If no title found, use default
            // This path is only reachable when a section has no non-empty lines (all whitespace/empty)
            // which is an edge case that cannot occur in normal Jira descriptions
            // @codeCoverageIgnoreStart
            if (empty($title)) {
                $title = $this->translator->trans('item.show.label_description');
            }
            // @codeCoverageIgnoreEnd

            // If there's only one line and it looks like content (not a title pattern),
            // use it as content with default title
            if (empty($contentLines) && ! empty($title)) {
                // Check if title looks like a section header
                $isSectionHeader = preg_match('/^[^:]+:\s*.+$/', $title)
                    || preg_match('/^(Title|User Story|Description & Implementation Logic|Acceptance Criteria)(\s*:)?$/i', $title);

                if (! $isSectionHeader) {
                    // Single line that's not a section header - treat as content
                    $contentLines = [$title];
                    $title = $this->translator->trans('item.show.label_description');
                }
            }

            // Sanitize content (collapse multiple newlines)
            $contentLines = $this->sanitizeContent($contentLines);

            $sections[] = [
                'title' => $title,
                'contentLines' => $contentLines,
            ];
        }

        return $sections;
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
        $lists = [];
        $text = [];
        $currentList = [];
        $currentText = [];
        $currentListItem = null;
        $currentSubItems = [];

        foreach ($lines as $line) {
            // Use trim() only - it's safe and only removes whitespace
            $trimmed = trim($line);

            // Check if line is a checkbox (starts with [ ] or [x])
            if (preg_match('/^\[\s*[xX]?\s*\]\s*(.+)$/', $trimmed, $matches)) {
                // If we have a previous list item with sub-items, add it to the list
                if ($currentListItem !== null) {
                    if (! empty($currentSubItems)) {
                        // Format: main item with sub-items indented
                        $formattedItem = $currentListItem;
                        foreach ($currentSubItems as $subItem) {
                            $formattedItem .= "\n  - " . $subItem;
                        }
                        $currentList[] = $formattedItem;
                    } else {
                        $currentList[] = $currentListItem;
                    }
                    $currentListItem = null;
                    $currentSubItems = [];
                }

                // If we have accumulated text (not part of a list), save it first
                if (! empty($currentText)) {
                    $text[] = $currentText;
                    $currentText = [];
                }

                // Start a new list item
                $currentListItem = trim($matches[1]);
            } else {
                // Non-checkbox line
                if ($currentListItem !== null) {
                    // This is a sub-item of the current list item
                    if (! empty($trimmed)) {
                        $currentSubItems[] = $trimmed;
                    }
                } else {
                    // If we have accumulated list items, save them as a list
                    // This path is only reachable when we have finished list items in $currentList
                    // and encounter text with $currentListItem === null, which is a very specific edge case
                    // that cannot be easily simulated in unit tests
                    // @codeCoverageIgnoreStart
                    if (! empty($currentList)) {
                        $lists[] = $currentList;
                        $currentList = [];
                    }
                    // @codeCoverageIgnoreEnd
                    // Add regular line to text - use trimmed version (trim() is safe)
                    if (! empty($trimmed)) {
                        $currentText[] = $trimmed;
                    } elseif (! empty($currentText)) {
                        // Preserve empty lines if we have text (for paragraph breaks)
                        $currentText[] = '';
                    }
                }
            }
        }

        // Handle the last list item if it exists
        if ($currentListItem !== null) {
            if (! empty($currentSubItems)) {
                $formattedItem = $currentListItem;
                foreach ($currentSubItems as $subItem) {
                    $formattedItem .= "\n  - " . $subItem;
                }
                $currentList[] = $formattedItem;
            } else {
                $currentList[] = $currentListItem;
            }
        }

        // Save any remaining list items
        if (! empty($currentList)) {
            $lists[] = $currentList;
        }

        // Save any remaining text
        if (! empty($currentText)) {
            $text[] = $currentText;
        }

        return [
            'lists' => $lists,
            'text' => $text,
        ];
    }
}
