<?php

declare(strict_types=1);

namespace App\Responder;

use App\Service\CommentBodyParser;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;

/**
 * Renders parsed PR/MR comment body segments to CLI output.
 */
class PrCommentBodyRenderer
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly CommentBodyParser $bodyParser,
        private readonly Logger $logger,
    ) {
    }

    public function render(string $body): void
    {
        foreach ($this->bodyParser->parse($body) as $segment) {
            $this->renderCommentSegment($segment);
        }
    }

    /**
     * @param array<string, mixed> $segment
     */
    protected function renderCommentSegment(array $segment): void
    {
        if ($segment['type'] === 'text') {
            $this->renderTextSegment($segment);

            return;
        }
        if ($segment['type'] === 'list') {
            $this->renderListSegment($segment);

            return;
        }
        if ($segment['type'] === 'table' && isset($segment['headers'], $segment['rows'])) {
            $this->renderTableSegment($segment['headers'], $segment['rows']);
        }
    }

    /**
     * @param array<string, mixed> $segment
     */
    protected function renderTextSegment(array $segment): void
    {
        if (! isset($segment['content']) || $segment['content'] === '') {
            return;
        }
        $text = $this->stripBackticks($segment['content']);
        if ($this->helper->colorHelper !== null) {
            $text = $this->helper->colorHelper->format('text_content', $text);
        }
        $this->logger->text(Logger::VERBOSITY_NORMAL, $text);
    }

    /**
     * @param array<string, mixed> $segment
     */
    protected function renderListSegment(array $segment): void
    {
        if (! isset($segment['items']) || $segment['items'] === []) {
            return;
        }
        $items = array_map(fn (string $item) => $this->stripBackticks($item), $segment['items']);
        if ($this->helper->colorHelper !== null) {
            $items = array_map(fn (string $item) => $this->helper->colorHelper->format('listing_item', $item), $items);
        }
        $this->logger->listing(Logger::VERBOSITY_NORMAL, $items);
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string>> $rows
     */
    protected function renderTableSegment(array $headers, array $rows): void
    {
        // Defensive: parser does not currently produce table segments with 0 data rows
        // @codeCoverageIgnoreStart
        if ($rows === []) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $safeHeaders = array_map(fn (string $h) => $this->stripBackticks($h), $headers);
        $safeRows = array_map(
            fn (array $row) => array_map(fn (string $cell) => $this->stripBackticks($cell), $row),
            $rows
        );

        $columns = [];
        foreach (array_keys($safeHeaders) as $idx) {
            $headerText = $safeHeaders[$idx];
            $columns[] = new Column((string) $idx, $headerText, fn (array $row): string => (string) ($row[$idx] ?? ''));
        }

        $viewConfig = new PageViewConfig([
            new Section('', [new TableBlock($columns)]),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($safeRows, $this->logger, []);
    }

    /**
     * Strips backticks so output is safe when copied into a shell.
     */
    protected function stripBackticks(string $value): string
    {
        return str_replace(['```', '``', '`'], ['', '', ''], $value);
    }
}
