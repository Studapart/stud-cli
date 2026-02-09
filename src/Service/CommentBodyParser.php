<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Parses PR/MR comment body (markdown-like) into segments: text paragraphs, tables, and lists.
 * Used to render comments with clean formatting (sections, TableBlock for tables, listing for lists).
 */
class CommentBodyParser
{
    /**
     * Parses body into segments. Each segment is:
     * - ['type' => 'text', 'content' => string]
     * - ['type' => 'list', 'items' => array<int, string>]
     * - ['type' => 'table', 'headers' => array<int, string>, 'rows' => array<int, array<int, string>>].
     *
     * @return array<int, array{type: string, content?: string, items?: array<int, string>, headers?: array<int, string>, rows?: array<int, array<int, string>>}>
     */
    public function parse(string $body): array
    {
        $body = $this->normalizeLineEndings($body);
        $lines = $this->trimmedLines($body);

        return $this->splitIntoSegments($lines);
    }

    protected function normalizeLineEndings(string $body): string
    {
        return str_replace(["\r\n", "\r"], "\n", $body);
    }

    /**
     * @return array<int, string>
     */
    protected function trimmedLines(string $body): array
    {
        $lines = explode("\n", $body);
        $out = [];
        foreach ($lines as $line) {
            $out[] = trim($line);
        }

        return $out;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array{type: string, content?: string, items?: array<int, string>, headers?: array<int, string>, rows?: array<int, array<int, string>>}>
     */
    protected function splitIntoSegments(array $lines): array
    {
        $segments = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            if ($line === '') {
                ++$i;

                continue;
            }

            if ($this->isTableRow($line)) {
                $tableResult = $this->consumeTable($lines, $i);
                $segments[] = $tableResult['segment'];
                $i = $tableResult['nextIndex'];

                continue;
            }

            if ($this->isListItem($line)) {
                $listResult = $this->consumeList($lines, $i);
                $segments[] = $listResult['segment'];
                $i = $listResult['nextIndex'];

                continue;
            }

            $textResult = $this->consumeParagraph($lines, $i);
            if ($textResult['segment']['content'] !== '') {
                $segments[] = $textResult['segment'];
            }
            $i = $textResult['nextIndex'];
        }

        return $segments;
    }

    protected function isTableRow(string $line): bool
    {
        return $line !== '' && str_contains($line, '|');
    }

    protected function isListItem(string $line): bool
    {
        // Empty line is skipped in parse(); this branch is defensive.
        // @codeCoverageIgnoreStart
        if ($line === '') {
            return false;
        }
        // @codeCoverageIgnoreEnd
        if (preg_match('/^[\*\-]\s+/', $line) === 1) {
            return true;
        }
        if (preg_match('/^\d+\.\s+/', $line) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, string> $lines
     * @return array{segment: array{type: string, headers: array<int, string>, rows: array<int, array<int, string>>}, nextIndex: int}
     */
    protected function consumeTable(array $lines, int $start): array
    {
        $rows = [];
        $i = $start;

        while ($i < count($lines) && $this->isTableRow($lines[$i])) {
            $cells = $this->parseTableRow($lines[$i]);
            if ($cells !== null) {
                $rows[] = $cells;
            }
            ++$i;
        }

        $headers = [];
        $dataRows = [];
        if (count($rows) >= 1) {
            $first = $rows[0];
            $secondIsSeparator = isset($rows[1]) && $this->isTableSeparatorRow($rows[1]);
            if ($secondIsSeparator && count($rows) >= 3) {
                $headers = $first;
                $dataRows = array_slice($rows, 2);
            } elseif (count($rows) >= 2) {
                $headers = $first;
                $dataRows = array_slice($rows, 1);
            } else {
                $dataRows = $rows;
            }
        }

        return [
            'segment' => [
                'type' => 'table',
                'headers' => $headers,
                'rows' => $dataRows,
            ],
            'nextIndex' => $i,
        ];
    }

    /**
     * @return array<int, string>|null
     */
    protected function parseTableRow(string $line): ?array
    {
        $trimmed = trim($line, " \t\n\r\0\x0B|");
        if ($trimmed === '') {
            return [];
        }
        $cells = array_map('trim', explode('|', $trimmed));

        return array_values($cells);
    }

    /**
     * @param array<int, string> $row
     */
    protected function isTableSeparatorRow(array $row): bool
    {
        $joined = implode('', $row);

        return preg_match('/^[\s\-:]+$/', $joined) === 1;
    }

    /**
     * @param array<int, string> $lines
     * @return array{segment: array{type: string, items: array<int, string>}, nextIndex: int}
     */
    protected function consumeList(array $lines, int $start): array
    {
        $items = [];
        $i = $start;

        while ($i < count($lines) && $this->isListItem($lines[$i])) {
            $line = $lines[$i];
            $stripped = preg_replace('/^\d+\.\s+/', '', preg_replace('/^[\*\-]\s+/', '', $line) ?? '') ?? '';
            $items[] = $stripped;
            ++$i;
        }

        return [
            'segment' => [
                'type' => 'list',
                'items' => $items,
            ],
            'nextIndex' => $i,
        ];
    }

    /**
     * @param array<int, string> $lines
     * @return array{segment: array{type: string, content: string}, nextIndex: int}
     */
    protected function consumeParagraph(array $lines, int $start): array
    {
        $parts = [];
        $i = $start;

        while ($i < count($lines)) {
            $line = $lines[$i];
            if ($line === '' || $this->isTableRow($line) || $this->isListItem($line)) {
                break;
            }
            $parts[] = $line;
            ++$i;
        }

        $content = implode("\n", $parts);

        return [
            'segment' => [
                'type' => 'text',
                'content' => $content,
            ],
            'nextIndex' => $i,
        ];
    }
}
