<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\PullRequestComment;
use App\Response\PrCommentsResponse;
use App\Service\ColorHelper;
use App\Service\CommentBodyParser;
use App\Service\TranslationService;
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
    public function __construct(
        private readonly TranslationService $translator,
        private readonly CommentBodyParser $bodyParser,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function respond(SymfonyStyle $io, PrCommentsResponse $response): void
    {
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        $sectionTitle = $this->translator->trans('pr.comments.section', ['number' => $response->pullNumber]);
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        $this->renderIssueComments($io, $response);
        $this->renderReviews($io, $response);
        $this->renderReviewComments($io, $response);
    }

    protected function renderIssueComments(SymfonyStyle $io, PrCommentsResponse $response): void
    {
        $sectionTitle = $this->translator->trans('pr.comments.issue_comments');
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        if (empty($response->issueComments)) {
            $io->note($this->translator->trans('pr.comments.no_comments'));

            return;
        }

        foreach ($response->issueComments as $comment) {
            $this->renderSingleComment($io, $comment, false);
        }
    }

    protected function renderReviews(SymfonyStyle $io, PrCommentsResponse $response): void
    {
        $sectionTitle = $this->translator->trans('pr.comments.reviews');
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        if (empty($response->reviews)) {
            $io->note($this->translator->trans('pr.comments.no_comments'));

            return;
        }

        foreach ($response->reviews as $comment) {
            $this->renderSingleComment($io, $comment, false);
        }
    }

    protected function renderReviewComments(SymfonyStyle $io, PrCommentsResponse $response): void
    {
        $sectionTitle = $this->translator->trans('pr.comments.review_comments');
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        if (empty($response->reviewComments)) {
            $io->note($this->translator->trans('pr.comments.no_comments'));

            return;
        }

        foreach ($response->reviewComments as $comment) {
            $this->renderSingleComment($io, $comment, true);
        }
    }

    protected function renderSingleComment(SymfonyStyle $io, PullRequestComment $comment, bool $isReview): void
    {
        $authorLabel = $this->translator->trans('pr.comments.table.author');
        $dateLabel = $this->translator->trans('pr.comments.table.date');
        $headerLine = "{$authorLabel}: {$comment->author} · {$dateLabel}: {$comment->date->format('Y-m-d H:i')}";
        if ($isReview && $comment->path !== null) {
            $pathLine = $comment->path . ($comment->line !== null ? ':' . $comment->line : '');
            $headerLine .= " · {$pathLine}";
        }
        if ($this->colorHelper !== null) {
            $headerLine = $this->colorHelper->format('section_title', $headerLine);
        }
        $io->section($headerLine);

        $segments = $this->bodyParser->parse($comment->body);

        foreach ($segments as $segment) {
            if ($segment['type'] === 'text' && isset($segment['content']) && $segment['content'] !== '') {
                $text = $this->stripBackticks($segment['content']);
                if ($this->colorHelper !== null) {
                    $text = $this->colorHelper->format('text_content', $text);
                }
                $io->text($text);
            } elseif ($segment['type'] === 'list' && isset($segment['items']) && $segment['items'] !== []) {
                $items = array_map(fn (string $item) => $this->stripBackticks($item), $segment['items']);
                if ($this->colorHelper !== null) {
                    $items = array_map(fn (string $item) => $this->colorHelper->format('listing_item', $item), $items);
                }
                $io->listing($items);
            } elseif ($segment['type'] === 'table' && isset($segment['headers'], $segment['rows'])) {
                $this->renderTableSegment($io, $segment['headers'], $segment['rows']);
            }
        }
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string>> $rows
     */
    protected function renderTableSegment(SymfonyStyle $io, array $headers, array $rows): void
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
        ], $this->translator, $this->colorHelper);

        $viewConfig->render($safeRows, $io, []);
    }

    /**
     * Strips backticks so output is safe when copied into a shell (avoids command substitution).
     */
    private function stripBackticks(string $s): string
    {
        return str_replace(['```', '``', '`'], ['', '', ''], $s);
    }
}
