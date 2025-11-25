<?php

namespace App\Handler;

use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\TableSeparator;

class ItemShowHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly array $jiraConfig,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io, string $key): void
    {
        $key = strtoupper($key);
        $io->section($this->translator->trans('item.show.section', ['key' => $key]));
        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>{$this->translator->trans('item.show.fetching', ['key' => $key])}</>");
        }
        try {
            $issue = $this->jiraService->getIssue($key, true);
        } catch (\Exception $e) {
            $io->error($this->translator->trans('item.show.error_not_found', ['key' => $key]));
            return;
        }

        $io->definitionList(
            [$this->translator->trans('item.show.label_key') => $issue->key],
            [$this->translator->trans('item.show.label_title') => $issue->title],
            [$this->translator->trans('item.show.label_status') => $issue->status],
            [$this->translator->trans('item.show.label_assignee') => $issue->assignee],
            [$this->translator->trans('item.show.label_type') => $issue->issueType],
            [$this->translator->trans('item.show.label_labels') => !empty($issue->labels) ? implode(', ', $issue->labels) : $this->translator->trans('item.show.label_none')],
            new TableSeparator(), // separator
            [$this->translator->trans('item.show.label_link') => $this->jiraConfig['JIRA_URL'] . '/browse/' . $issue->key]
        );

        $this->displayDescription($io, $issue->description);
    }

    /**
     * Sanitizes content by collapsing multiple consecutive newlines into single newlines.
     * Replaces 2+ consecutive newlines (\n{2,}) with a single newline.
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
    protected function parseDescriptionSections(string $description): array
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
                if (!empty($currentSection)) {
                    $sectionParts[] = $currentSection;
                }
                $currentSection = [];
            } else {
                $currentSection[] = $line;
            }
        }
        
        // Add last section
        if (!empty($currentSection)) {
            $sectionParts[] = $currentSection;
        }
        
        // If no dividers found, treat whole description as one section
        if (empty($sectionParts)) {
            $sectionParts = [explode("\n", $description)];
        }
        
        // Step 2: Process each section - trim lines once
        foreach ($sectionParts as $sectionLines) {
            // Find first non-empty line as title
            $title = '';
            $contentLines = [];
            $titleFound = false;
            
            foreach ($sectionLines as $line) {
                // Use trim() only - it's safe and only removes whitespace
                $trimmed = trim($line);
                if (!empty($trimmed)) {
                    if (!$titleFound) {
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
            if (empty($title)) {
                $title = $this->translator->trans('item.show.label_description');
            }
            
            // If there's only one line and it looks like content (not a title pattern), 
            // use it as content with default title
            if (empty($contentLines) && !empty($title)) {
                // Check if title looks like a section header
                $isSectionHeader = preg_match('/^[^:]+:\s*.+$/', $title) 
                    || preg_match('/^(Title|User Story|Description & Implementation Logic|Acceptance Criteria)(\s*:)?$/i', $title);
                
                if (!$isSectionHeader) {
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
     * Displays the description in dedicated sections.
     * Detects checkbox lists (lines starting with [ ]) and displays them using $io->listing().
     */
    protected function displayDescription(SymfonyStyle $io, string $description): void
    {
        if (empty(trim($description))) {
            return;
        }

        $sections = $this->parseDescriptionSections($description);

        foreach ($sections as $section) {
            $io->section($section['title']);
            if (!empty($section['contentLines'])) {
                $this->displayContent($io, $section['contentLines']);
            }
        }
    }

    /**
     * Displays content lines, handling checkbox lists specially.
     * Lines starting with [ ] are displayed as a list using $io->listing().
     * Regular text lines are displayed using $io->text() with an array.
     * Uses trim() only to safely remove whitespace.
     */
    protected function displayContent(SymfonyStyle $io, array $lines): void
    {
        $currentList = [];
        $currentText = [];
        
        foreach ($lines as $line) {
            // Use trim() only - it's safe and only removes whitespace
            $trimmed = trim($line);
            
            // Check if line is a checkbox (starts with [ ] or [x])
            if (preg_match('/^\[\s*[xX]?\s*\]\s*(.+)$/', $trimmed, $matches)) {
                // If we have accumulated text, display it first
                if (!empty($currentText)) {
                    $io->text($currentText);
                    $currentText = [];
                }
                // Add checkbox item to list
                $currentList[] = trim($matches[1]);
            } else {
                // If we have accumulated list items, display them as a list
                if (!empty($currentList)) {
                    $io->listing($currentList);
                    $currentList = [];
                }
                // Add regular line to text - use trimmed version (trim() is safe)
                if (!empty($trimmed)) {
                    $currentText[] = $trimmed;
                } elseif (!empty($currentText)) {
                    // Preserve empty lines if we have text (for paragraph breaks)
                    $currentText[] = '';
                }
            }
        }
        
        // Display any remaining list items
        if (!empty($currentList)) {
            $io->listing($currentList);
        }
        
        // Display any remaining text
        if (!empty($currentText)) {
            $io->text($currentText);
        }
    }
}