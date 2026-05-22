<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestFeedbackActions;
use App\DTO\PullRequestFeedbackComment;
use App\DTO\PullRequestFeedbackConversation;
use App\DTO\PullRequestFeedbackIds;
use App\DTO\PullRequestFeedbackLocation;
use App\DTO\PullRequestFeedbackReview;
use App\DTO\PullRequestFeedbackState;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GithubConversationProvider
{
    private const CONNECTION_COMMENTS = 'comments';
    private const CONNECTION_REVIEWS = 'reviews';
    private const CONNECTION_REVIEW_THREADS = 'reviewThreads';
    private const KIND_PR_COMMENT = 'pr_comment';
    private const KIND_REVIEW = 'review';
    private const KIND_REVIEW_COMMENT = 'review_comment';
    private const KIND_REVIEW_THREAD = 'review_thread';
    private const PROVIDER_GITHUB = 'github';
    private const REVIEW_STATE_DISMISSED = 'DISMISSED';
    private const UNKNOWN_AUTHOR = 'unknown';
    private const UNKNOWN_GRAPHQL_ERROR = 'Unknown GraphQL error';

    /**
     * @param \Closure(string, string, string, array<string, mixed>): ResponseInterface $apiRequest
     */
    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly \Closure $apiRequest,
        private readonly PullRequestFeedbackTargetResolver $targetResolver = new PullRequestFeedbackTargetResolver(),
    ) {
    }

    /**
     * @return PullRequestFeedbackConversation[]
     */
    public function getPullRequestFeedbackConversations(int $pullNumber): array
    {
        $conversations = array_merge(
            $this->fetchGithubIssueCommentConversations($pullNumber),
            $this->fetchGithubReviewConversations($pullNumber),
            $this->fetchGithubReviewThreadConversations($pullNumber),
        );

        usort($conversations, fn (PullRequestFeedbackConversation $a, PullRequestFeedbackConversation $b): int => $this->conversationDate($a) <=> $this->conversationDate($b));

        return $conversations;
    }

    /**
     * @return PullRequestFeedbackConversation[]
     */
    protected function fetchGithubIssueCommentConversations(int $pullNumber): array
    {
        return array_map(
            fn (array $node): PullRequestFeedbackConversation => $this->mapGithubIssueCommentConversation($node),
            $this->fetchGithubPullRequestConnection($pullNumber, self::CONNECTION_COMMENTS)
        );
    }

    /**
     * @return PullRequestFeedbackConversation[]
     */
    protected function fetchGithubReviewConversations(int $pullNumber): array
    {
        $conversations = [];
        foreach ($this->fetchGithubPullRequestConnection($pullNumber, self::CONNECTION_REVIEWS) as $node) {
            $conversation = $this->mapGithubReviewConversation($node);
            if ($conversation !== null) {
                $conversations[] = $conversation;
            }
        }

        return $conversations;
    }

    /**
     * @return PullRequestFeedbackConversation[]
     */
    protected function fetchGithubReviewThreadConversations(int $pullNumber): array
    {
        return array_map(
            fn (array $node): PullRequestFeedbackConversation => $this->mapGithubReviewThreadConversation($node),
            $this->fetchGithubPullRequestConnection($pullNumber, self::CONNECTION_REVIEW_THREADS)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchGithubPullRequestConnection(int $pullNumber, string $connection): array
    {
        $nodes = [];
        $cursor = null;
        do {
            $payload = $this->graphqlRequest($this->githubFeedbackQuery($connection), [
                'owner' => $this->owner,
                'repo' => $this->repo,
                'number' => $pullNumber,
                'cursor' => $cursor,
            ], "Failed to get threaded feedback for pull request #{$pullNumber}.");
            $page = $payload['data']['repository']['pullRequest'][$connection] ?? [];
            $pageNodes = is_array($page) && is_array($page['nodes'] ?? null) ? $page['nodes'] : [];
            $nodes = array_merge($nodes, $pageNodes);
            $pageInfo = is_array($page) && is_array($page['pageInfo'] ?? null) ? $page['pageInfo'] : [];
            $cursor = isset($pageInfo['endCursor']) ? (string) $pageInfo['endCursor'] : null;
        } while ((bool) ($pageInfo['hasNextPage'] ?? false));

        return $nodes;
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    protected function graphqlRequest(string $query, array $variables, string $errorMessage): array
    {
        $request = $this->apiRequest;
        $payload = $request('POST', '/graphql', $errorMessage, [
            'json' => ['query' => $query, 'variables' => $variables],
        ])->toArray(false);

        if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            $message = isset($payload['errors'][0]['message']) ? (string) $payload['errors'][0]['message'] : self::UNKNOWN_GRAPHQL_ERROR;

            throw new \RuntimeException($errorMessage . ' ' . $message);
        }

        return $payload;
    }

    protected function githubFeedbackQuery(string $connection): string
    {
        return sprintf(
            'query PullRequestFeedback($owner: String!, $repo: String!, $number: Int!, $cursor: String) {
  repository(owner: $owner, name: $repo) {
    pullRequest(number: $number) {
      %s(first: 50, after: $cursor) {
        pageInfo { hasNextPage endCursor }
        nodes %s
      }
    }
  }
}',
            $connection,
            $this->githubFeedbackSelection($connection),
        );
    }

    protected function githubFeedbackSelection(string $connection): string
    {
        return match ($connection) {
            self::CONNECTION_COMMENTS => '{ id databaseId author { login } body createdAt updatedAt isMinimized minimizedReason viewerCanMinimize }',
            self::CONNECTION_REVIEWS => '{ id databaseId author { login } body state submittedAt isMinimized minimizedReason viewerCanMinimize }',
            self::CONNECTION_REVIEW_THREADS => '{ id isResolved isOutdated path line startLine resolvedBy { login } viewerCanResolve viewerCanUnresolve comments(first: 100) { pageInfo { hasNextPage endCursor } nodes { id databaseId author { login } body createdAt updatedAt isMinimized minimizedReason outdated path line startLine pullRequestReview { id databaseId state body submittedAt author { login } } viewerCanMinimize } } }',
            default => throw new \InvalidArgumentException(sprintf("Unsupported GitHub feedback connection '%s'.", $connection)),
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function mapGithubIssueCommentConversation(array $node): PullRequestFeedbackConversation
    {
        $comment = $this->mapGithubFeedbackComment($node, self::KIND_PR_COMMENT, null, null, null);

        return new PullRequestFeedbackConversation(
            $comment->ids,
            self::KIND_PR_COMMENT,
            $comment->state,
            [$comment],
            $comment->actions,
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function mapGithubReviewConversation(array $node): ?PullRequestFeedbackConversation
    {
        $body = isset($node['body']) ? trim((string) $node['body']) : '';
        if ($body === '') {
            return null;
        }

        $review = $this->mapGithubReview($node);
        $comment = new PullRequestFeedbackComment($review->ids, $review->author, $review->date, $body, $review->state, null, $review->actions);

        return new PullRequestFeedbackConversation($review->ids, self::KIND_REVIEW, $review->state, [$comment], $review->actions, review: $review);
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function mapGithubReviewThreadConversation(array $node): PullRequestFeedbackConversation
    {
        $threadId = isset($node['id']) ? (string) $node['id'] : null;
        $location = $this->githubThreadLocation($node);
        $state = new PullRequestFeedbackState(
            resolved: isset($node['isResolved']) ? (bool) $node['isResolved'] : null,
            resolvable: (bool) ($node['viewerCanResolve'] ?? $node['viewerCanUnresolve'] ?? false),
            outdated: isset($node['isOutdated']) ? (bool) $node['isOutdated'] : null,
            resolvedBy: $this->githubAuthor($node['resolvedBy'] ?? null),
        );
        $actions = new PullRequestFeedbackActions(
            canReply: $threadId !== null,
            canResolve: (bool) ($node['viewerCanResolve'] ?? false),
            canReopen: (bool) ($node['viewerCanUnresolve'] ?? false),
        );
        $comments = $this->mapGithubThreadComments($node, $threadId, $location);
        $review = $comments !== [] ? $this->mapGithubThreadReview($node, $comments[0]) : null;
        $ids = new PullRequestFeedbackIds(
            self::PROVIDER_GITHUB,
            self::KIND_REVIEW_THREAD,
            threadId: $threadId,
            target: $this->targetResolver->targetFor(self::PROVIDER_GITHUB, self::KIND_REVIEW_THREAD, $threadId)
        );
        $commentPage = is_array($node['comments'] ?? null) ? $node['comments'] : [];
        $pageInfo = is_array($commentPage['pageInfo'] ?? null) ? $commentPage['pageInfo'] : [];

        return new PullRequestFeedbackConversation(
            $ids,
            self::KIND_REVIEW_THREAD,
            $state,
            $comments,
            $actions,
            location: $location,
            review: $review,
            truncated: (bool) ($pageInfo['hasNextPage'] ?? false)
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return PullRequestFeedbackComment[]
     */
    protected function mapGithubThreadComments(array $node, ?string $threadId, ?PullRequestFeedbackLocation $fallbackLocation): array
    {
        $comments = is_array($node['comments']['nodes'] ?? null) ? $node['comments']['nodes'] : [];

        return array_map(
            fn (array $comment): PullRequestFeedbackComment => $this->mapGithubFeedbackComment($comment, self::KIND_REVIEW_COMMENT, $threadId, $fallbackLocation, $comment['pullRequestReview'] ?? null),
            $comments
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $review
     */
    protected function mapGithubFeedbackComment(
        array $node,
        string $kind,
        ?string $threadId,
        ?PullRequestFeedbackLocation $fallbackLocation,
        ?array $review = null,
    ): PullRequestFeedbackComment {
        $ids = new PullRequestFeedbackIds(
            self::PROVIDER_GITHUB,
            $kind,
            id: isset($node['databaseId']) ? (string) $node['databaseId'] : null,
            nodeId: isset($node['id']) ? (string) $node['id'] : null,
            threadId: $threadId,
            reviewId: is_array($review) && isset($review['id']) ? (string) $review['id'] : null,
        );
        $state = new PullRequestFeedbackState(
            outdated: isset($node['outdated']) ? (bool) $node['outdated'] : null,
            minimized: isset($node['isMinimized']) ? (bool) $node['isMinimized'] : null,
            providerState: isset($node['minimizedReason']) ? (string) $node['minimizedReason'] : null,
        );
        $actions = new PullRequestFeedbackActions(
            canReply: $threadId !== null,
            canHide: (bool) ($node['viewerCanMinimize'] ?? false),
            canUnhide: (bool) (($node['isMinimized'] ?? false) && ($node['viewerCanMinimize'] ?? false)),
        );

        return new PullRequestFeedbackComment($ids, $this->githubAuthor($node['author'] ?? null), $this->githubDate($node, 'createdAt'), (string) ($node['body'] ?? ''), $state, $this->githubCommentLocation($node, $fallbackLocation), $actions);
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function mapGithubReview(array $node): PullRequestFeedbackReview
    {
        $ids = new PullRequestFeedbackIds(
            self::PROVIDER_GITHUB,
            self::KIND_REVIEW,
            id: isset($node['databaseId']) ? (string) $node['databaseId'] : null,
            nodeId: isset($node['id']) ? (string) $node['id'] : null,
            reviewId: isset($node['id']) ? (string) $node['id'] : null,
        );
        $state = new PullRequestFeedbackState(
            minimized: isset($node['isMinimized']) ? (bool) $node['isMinimized'] : null,
            dismissed: isset($node['state']) ? (string) $node['state'] === self::REVIEW_STATE_DISMISSED : null,
            providerState: isset($node['state']) ? (string) $node['state'] : null,
        );
        $actions = new PullRequestFeedbackActions(
            canHide: (bool) ($node['viewerCanMinimize'] ?? false),
            canUnhide: (bool) (($node['isMinimized'] ?? false) && ($node['viewerCanMinimize'] ?? false)),
        );

        return new PullRequestFeedbackReview($ids, $this->githubAuthor($node['author'] ?? null), $this->githubDate($node, 'submittedAt'), (string) ($node['body'] ?? ''), $state, $actions);
    }

    /**
     * @param array<string, mixed> $thread
     */
    protected function mapGithubThreadReview(array $thread, PullRequestFeedbackComment $firstComment): ?PullRequestFeedbackReview
    {
        $comments = is_array($thread['comments']['nodes'] ?? null) ? $thread['comments']['nodes'] : [];
        $review = is_array($comments[0]['pullRequestReview'] ?? null) ? $comments[0]['pullRequestReview'] : null;
        if ($review === null) {
            return null;
        }

        $mapped = $this->mapGithubReview($review);

        return new PullRequestFeedbackReview($mapped->ids, $mapped->author, $mapped->date, $mapped->body, $mapped->state, $firstComment->actions);
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function githubThreadLocation(array $node): ?PullRequestFeedbackLocation
    {
        if (! isset($node['path']) && ! isset($node['line']) && ! isset($node['startLine'])) {
            return null;
        }

        return new PullRequestFeedbackLocation(
            path: isset($node['path']) ? (string) $node['path'] : null,
            line: isset($node['line']) ? (int) $node['line'] : null,
            startLine: isset($node['startLine']) ? (int) $node['startLine'] : null,
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function githubCommentLocation(array $node, ?PullRequestFeedbackLocation $fallback): ?PullRequestFeedbackLocation
    {
        if (! isset($node['path']) && ! isset($node['line']) && ! isset($node['startLine'])) {
            return $fallback;
        }

        return new PullRequestFeedbackLocation(
            path: isset($node['path']) ? (string) $node['path'] : $fallback?->path,
            line: isset($node['line']) ? (int) $node['line'] : $fallback?->line,
            startLine: isset($node['startLine']) ? (int) $node['startLine'] : $fallback?->startLine,
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function githubDate(array $node, string $primaryField): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) ($node[$primaryField] ?? $node['createdAt'] ?? $node['submittedAt'] ?? 'now'));
    }

    protected function githubAuthor(mixed $author): string
    {
        return is_array($author) && isset($author['login']) ? (string) $author['login'] : self::UNKNOWN_AUTHOR;
    }

    protected function conversationDate(PullRequestFeedbackConversation $conversation): int
    {
        $date = $conversation->comments[0]->date ?? $conversation->review->date ?? new \DateTimeImmutable();

        return $date->getTimestamp();
    }
}
