<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\PullRequestComment;
use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\PrCommentsResponse;
use App\Service\CommentBodyParser;
use App\Service\DtoSerializer;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders PR/MR comments as sections: one section per comment with author and date
 * on the first lines, then body as paragraphs. Tables in the body are rendered via
 * TableBlock; lists via listing.
 */
class PrCommentsResponder
{
    private readonly DtoSerializer $serializer;

    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly CommentBodyParser $bodyParser,
        private readonly Logger $logger,
        ?DtoSerializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new DtoSerializer();
    }

    public function respond(SymfonyStyle $io, PrCommentsResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            if (! $response->isSuccess()) {
                return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
            }

            return new AgentJsonResponse(true, data: [
                'issueComments' => $this->serializer->serializeList($response->issueComments),
                'reviewComments' => $this->serializer->serializeList($response->reviewComments),
                'reviews' => $this->serializer->serializeList($response->reviews),
                'pullNumber' => $response->pullNumber,
            ]);
        }

        $this->helper->initSection($this->logger, 'pr.comments.section', ['number' => $response->pullNumber]);

        $this->renderIssueComments($response);
        $this->renderReviews($response);
        $this->renderReviewComments($response);

        return null;
    }

    protected function renderIssueComments(PrCommentsResponse $response): void
    {
        $this->helper->initSection($this->logger, 'pr.comments.issue_comments');

        if (empty($response->issueComments)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('pr.comments.no_comments'));

            return;
        }

        foreach ($response->issueComments as $comment) {
            $this->renderSingleComment($comment, false);
        }
    }

    protected function renderReviews(PrCommentsResponse $response): void
    {
        $this->helper->initSection($this->logger, 'pr.comments.reviews');

        if (empty($response->reviews)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('pr.comments.no_comments'));

            return;
        }

        foreach ($response->reviews as $comment) {
            $this->renderSingleComment($comment, false);
        }
    }

    protected function renderReviewComments(PrCommentsResponse $response): void
    {
        $this->helper->initSection($this->logger, 'pr.comments.review_comments');

        if (empty($response->reviewComments)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('pr.comments.no_comments'));

            return;
        }

        foreach ($response->reviewComments as $comment) {
            $this->renderSingleComment($comment, true);
        }
    }

    protected function renderSingleComment(PullRequestComment $comment, bool $isReview): void
    {
        $authorLabel = $this->helper->translator->trans('pr.comments.table.author');
        $dateLabel = $this->helper->translator->trans('pr.comments.table.date');
        $headerLine = "{$authorLabel}: {$comment->author} · {$dateLabel}: {$comment->date->format('Y-m-d H:i')}";
        if ($isReview && $comment->path !== null) {
            $pathLine = $comment->path . ($comment->line !== null ? ':' . $comment->line : '');
            $headerLine .= " · {$pathLine}";
        }
        if ($this->helper->colorHelper !== null) {
            $headerLine = $this->helper->colorHelper->format('section_title', $headerLine);
        }
        $this->logger->section(Logger::VERBOSITY_NORMAL, $headerLine);

        $segments = $this->bodyParser->parse($comment->body);
        foreach ($segments as $segment) {
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
            $headerText = $safeHeaders[$idx] ?? (string) ($idx + 1);
            $columns[] = new Column((string) $idx, $headerText, fn (array $row): string => (string) ($row[$idx] ?? ''));
        }

        $viewConfig = new PageViewConfig([
            new Section('', [new TableBlock($columns)]),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($safeRows, $this->logger, []);
    }

    /**
     * Strips backticks so output is safe when copied into a shell (avoids command substitution).
     */
    private function stripBackticks(string $s): string
    {
        return str_replace(['```', '``', '`'], ['', '', ''], $s);
    }
}
