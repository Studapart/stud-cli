<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Converts Atlassian Document Format (ADF) JSON to Markdown.
 * Walks the ADF tree and emits markdown for blocks and inlines.
 */
class AdfToMarkdownConverter
{
    /**
     * Convert an ADF document (array with type and content) to markdown string.
     *
     * @param array{type: string, content?: array<int, mixed>} $adf
     */
    public function convert(array $adf): string
    {
        $content = $adf['content'] ?? [];
        if (! is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }
            $parts[] = $this->convertBlock($node);
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertBlock(array $node): string
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'paragraph' => $this->convertParagraph($node),
            'heading' => $this->convertHeading($node),
            'bulletList' => $this->convertBulletList($node),
            'orderedList' => $this->convertOrderedList($node),
            'listItem' => $this->convertListItem($node),
            'codeBlock' => $this->convertCodeBlock($node),
            'blockquote' => $this->convertBlockquote($node),
            'panel' => $this->convertPanel($node),
            'rule' => '---',
            'table' => $this->convertTable($node),
            default => $this->convertUnknownBlock($node),
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertParagraph(array $node): string
    {
        return $this->convertInlineContent($node['content'] ?? []);
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertHeading(array $node): string
    {
        $level = (int) ($node['attrs']['level'] ?? 1);
        $level = max(1, min(6, $level));
        $prefix = str_repeat('#', $level);
        $text = $this->convertInlineContent($node['content'] ?? []);

        return $prefix . ' ' . $text;
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertBulletList(array $node): string
    {
        return $this->convertListItems($node['content'] ?? [], '-');
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertOrderedList(array $node): string
    {
        return $this->convertListItems($node['content'] ?? [], '1.');
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    protected function convertListItems(array $items, string $bullet): string
    {
        $lines = [];
        $n = 0;
        foreach ($items as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'listItem') {
                continue;
            }
            $n++;
            $marker = $bullet === '-' ? '-' : (string) $n . '.';
            $inner = $this->convertListItemContent($item['content'] ?? []);
            $lines[] = $marker . ' ' . $inner;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertListItem(array $node): string
    {
        return $this->convertListItemContent($node['content'] ?? []);
    }

    /**
     * @param array<int, array<string, mixed>> $content
     */
    protected function convertListItemContent(array $content): string
    {
        $parts = [];
        foreach ($content as $child) {
            if (! is_array($child)) {
                continue;
            }
            if (($child['type'] ?? '') === 'paragraph') {
                $parts[] = $this->convertParagraph($child);
            } else {
                $parts[] = $this->convertBlock($child);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertCodeBlock(array $node): string
    {
        $content = $node['content'] ?? [];
        $text = $this->convertInlineContent($content);

        return '```' . "\n" . $text . "\n" . '```';
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertBlockquote(array $node): string
    {
        $inner = [];
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $inner[] = $this->convertBlock($child);
            }
        }
        $block = implode("\n\n", array_filter($inner));
        $lines = explode("\n", $block);

        return implode("\n", array_map(static fn (string $line): string => '> ' . $line, $lines));
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertPanel(array $node): string
    {
        $inner = [];
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $inner[] = $this->convertBlock($child);
            }
        }

        return implode("\n\n", array_filter($inner));
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertTable(array $node): string
    {
        $content = $node['content'] ?? [];
        if (! is_array($content)) {
            return '';
        }
        $rows = [];
        foreach ($content as $rowNode) {
            if (! is_array($rowNode) || ($rowNode['type'] ?? '') !== 'tableRow') {
                continue;
            }
            $cells = [];
            foreach ($rowNode['content'] ?? [] as $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                $cells[] = $this->convertTableCell($cell);
            }
            $rows[] = '| ' . implode(' | ', $cells) . ' |';
        }
        if ($rows === []) {
            return '';
        }
        $header = array_shift($rows);
        $separator = '| ' . implode(' | ', array_fill(0, substr_count($header, '|') - 1, '---')) . ' |';

        return $header . "\n" . $separator . "\n" . implode("\n", $rows);
    }

    /**
     * @param array<string, mixed> $cell
     */
    protected function convertTableCell(array $cell): string
    {
        $content = $cell['content'] ?? [];
        $text = $this->convertInlineContent(is_array($content) ? $content : []);

        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertUnknownBlock(array $node): string
    {
        if (isset($node['content']) && is_array($node['content'])) {
            $parts = [];
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    $parts[] = $this->convertBlock($child);
                }
            }

            return implode("\n\n", array_filter($parts));
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $content
     */
    protected function convertInlineContent(array $content): string
    {
        $parts = [];
        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }
            $parts[] = $this->convertInline($node);
        }

        return implode('', $parts);
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function convertInline(array $node): string
    {
        $type = $node['type'] ?? '';
        $text = $this->getInlineText($node);
        $marks = $node['marks'] ?? [];
        if (! is_array($marks)) {
            $marks = [];
        }
        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }
            $text = $this->applyMark($text, $mark);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function getInlineText(array $node): string
    {
        $type = $node['type'] ?? '';
        if ($type === 'text') {
            return (string) ($node['text'] ?? '');
        }
        if ($type === 'hardBreak') {
            return "\n";
        }
        if ($type === 'mention') {
            $attrs = $node['attrs'] ?? [];

            return isset($attrs['text']) ? (string) $attrs['text'] : '';
        }
        if (isset($node['content']) && is_array($node['content'])) {
            return $this->convertInlineContent($node['content']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $mark
     */
    protected function applyMark(string $text, array $mark): string
    {
        if ($text === '') {
            return $text;
        }
        $type = $mark['type'] ?? '';

        return match ($type) {
            'strong' => '**' . $text . '**',
            'em' => '*' . $text . '*',
            'code' => '`' . $text . '`',
            'link' => '[' . $text . '](' . ($mark['attrs']['href'] ?? '') . ')',
            'strike' => '~~' . $text . '~~',
            'underline' => $text,
            default => $text,
        };
    }
}
