<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\PullRequestComment;
use App\Response\PrCommentsResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;

/**
 * Renders the legacy flat PR/MR comments CLI view.
 */
class PrFlatCommentsRenderer
{
    private const HEADER_SEPARATOR = ' · ';

    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        private readonly PrCommentBodyRenderer $bodyRenderer,
    ) {
    }

    public function render(PrCommentsResponse $response): void
    {
        $this->renderIssueComments($response);
        $this->renderReviews($response);
        $this->renderReviewComments($response);
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
        $headerLine = $this->commentHeader($comment, $isReview);
        if ($this->helper->colorHelper !== null) {
            $headerLine = $this->helper->colorHelper->format('section_title', $headerLine);
        }
        $this->logger->section(Logger::VERBOSITY_NORMAL, $headerLine);
        $this->bodyRenderer->render($comment->body);
    }

    protected function commentHeader(PullRequestComment $comment, bool $isReview): string
    {
        $authorLabel = $this->helper->translator->trans('pr.comments.table.author');
        $dateLabel = $this->helper->translator->trans('pr.comments.table.date');
        $parts = [
            "{$authorLabel}: {$comment->author}",
            "{$dateLabel}: {$comment->date->format('Y-m-d H:i')}",
        ];

        if ($isReview && $comment->path !== null) {
            $parts[] = $comment->path . ($comment->line !== null ? ':' . $comment->line : '');
        }

        return implode(self::HEADER_SEPARATOR, $parts);
    }
}
