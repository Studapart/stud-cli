<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestFeedbackActions;
use App\DTO\PullRequestFeedbackComment;
use App\DTO\PullRequestFeedbackConversation;
use App\DTO\PullRequestFeedbackIds;
use App\DTO\PullRequestFeedbackLocation;
use App\DTO\PullRequestFeedbackState;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GitLabConversationProvider
{
    private const DISCUSSIONS_PAGE_SIZE = 100;
    private const KIND_NOTE = 'note';
    private const KIND_PR_COMMENT = 'pr_comment';
    private const KIND_REVIEW_THREAD = 'review_thread';
    private const PROVIDER_GITLAB = 'gitlab';
    private const UNKNOWN_AUTHOR = 'unknown';

    /**
     * @param \Closure(string, string, string, array<string, mixed>): ResponseInterface $apiRequest
     */
    public function __construct(
        private readonly string $projectPath,
        private readonly \Closure $apiRequest,
        private readonly PullRequestFeedbackTargetResolver $targetResolver = new PullRequestFeedbackTargetResolver(),
    ) {
    }

    /**
     * @return PullRequestFeedbackConversation[]
     */
    public function getPullRequestFeedbackConversations(int $pullNumber): array
    {
        $conversations = array_map(
            fn (array $discussion): PullRequestFeedbackConversation => $this->mapGitLabDiscussionConversation($discussion),
            $this->fetchAllGitLabDiscussions($pullNumber)
        );

        usort($conversations, fn (PullRequestFeedbackConversation $a, PullRequestFeedbackConversation $b): int => $this->conversationDate($a) <=> $this->conversationDate($b));

        return $conversations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAllGitLabDiscussions(int $pullNumber): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$pullNumber}/discussions?"
            . http_build_query(['per_page' => self::DISCUSSIONS_PAGE_SIZE]);
        $discussions = [];
        $page = 1;

        while (true) {
            $request = $this->apiRequest;
            $response = $request('GET', $apiUrl . '&page=' . $page, "Failed to get threaded feedback for merge request #{$pullNumber}.", []);
            $pageDiscussions = $response->toArray();
            $discussions = array_merge($discussions, $pageDiscussions);
            if (empty($pageDiscussions) || ! $this->hasNextPage($response, $page)) {
                break;
            }
            ++$page;
        }

        return $discussions;
    }

    /**
     * @param array<string, mixed> $discussion
     */
    protected function mapGitLabDiscussionConversation(array $discussion): PullRequestFeedbackConversation
    {
        $discussionId = isset($discussion['id']) ? (string) $discussion['id'] : null;
        $position = is_array($discussion['position'] ?? null) ? $discussion['position'] : null;
        $location = $position !== null ? $this->mapGitLabLocation($position) : null;
        $comments = $this->mapGitLabDiscussionComments($discussion, $discussionId, $location);
        $firstState = $comments[0]->state ?? new PullRequestFeedbackState();
        $type = $position !== null ? self::KIND_REVIEW_THREAD : self::KIND_PR_COMMENT;
        $actions = new PullRequestFeedbackActions(
            canReply: $discussionId !== null,
            canResolve: (bool) ($firstState->resolvable && $firstState->resolved === false),
            canReopen: (bool) ($firstState->resolvable && $firstState->resolved === true),
        );
        $ids = new PullRequestFeedbackIds(
            self::PROVIDER_GITLAB,
            $type,
            discussionId: $discussionId,
            threadId: $discussionId,
            target: $this->targetResolver->targetFor(self::PROVIDER_GITLAB, $type, $discussionId)
        );

        return new PullRequestFeedbackConversation($ids, $type, $firstState, $comments, $actions, location: $location);
    }

    /**
     * @param array<string, mixed> $discussion
     * @return PullRequestFeedbackComment[]
     */
    protected function mapGitLabDiscussionComments(array $discussion, ?string $discussionId, ?PullRequestFeedbackLocation $location): array
    {
        $notes = is_array($discussion['notes'] ?? null) ? $discussion['notes'] : [];

        return array_map(
            fn (array $note): PullRequestFeedbackComment => $this->mapGitLabNoteComment($note, $discussionId, $location),
            $notes
        );
    }

    /**
     * @param array<string, mixed> $note
     */
    protected function mapGitLabNoteComment(array $note, ?string $discussionId, ?PullRequestFeedbackLocation $location): PullRequestFeedbackComment
    {
        $state = $this->mapGitLabNoteState($note);

        return new PullRequestFeedbackComment(
            $this->mapGitLabNoteIds($note, $discussionId),
            $this->gitLabAuthor($note['author'] ?? null) ?? self::UNKNOWN_AUTHOR,
            $this->gitLabDate($note),
            (string) ($note['body'] ?? ''),
            $state,
            $location,
            $this->mapGitLabNoteActions($state, $discussionId)
        );
    }

    /**
     * @param array<string, mixed> $note
     */
    protected function mapGitLabNoteState(array $note): PullRequestFeedbackState
    {
        return new PullRequestFeedbackState(
            resolved: isset($note['resolved']) ? (bool) $note['resolved'] : null,
            resolvable: isset($note['resolvable']) ? (bool) $note['resolvable'] : null,
            providerState: isset($note['type']) ? (string) $note['type'] : null,
            resolvedBy: $this->gitLabAuthor($note['resolved_by'] ?? null),
            resolvedAt: isset($note['resolved_at']) ? new \DateTimeImmutable((string) $note['resolved_at']) : null,
        );
    }

    protected function mapGitLabNoteActions(PullRequestFeedbackState $state, ?string $discussionId): PullRequestFeedbackActions
    {
        $resolved = $state->resolved === true;
        $resolvable = $state->resolvable === true;

        return new PullRequestFeedbackActions(
            canReply: $discussionId !== null,
            canResolve: $resolvable && ! $resolved,
            canReopen: $resolvable && $resolved,
        );
    }

    /**
     * @param array<string, mixed> $note
     */
    protected function mapGitLabNoteIds(array $note, ?string $discussionId): PullRequestFeedbackIds
    {
        return new PullRequestFeedbackIds(
            self::PROVIDER_GITLAB,
            self::KIND_NOTE,
            id: isset($note['id']) ? (string) $note['id'] : null,
            discussionId: $discussionId,
            noteId: isset($note['id']) ? (string) $note['id'] : null,
        );
    }

    /**
     * @param array<string, mixed> $position
     */
    protected function mapGitLabLocation(array $position): PullRequestFeedbackLocation
    {
        return new PullRequestFeedbackLocation(
            path: isset($position['new_path']) ? (string) $position['new_path'] : (isset($position['old_path']) ? (string) $position['old_path'] : null),
            line: isset($position['new_line']) ? (int) $position['new_line'] : (isset($position['old_line']) ? (int) $position['old_line'] : null),
            startLine: isset($position['line_range']['start']['new_line']) ? (int) $position['line_range']['start']['new_line'] : null,
            side: isset($position['position_type']) ? (string) $position['position_type'] : null,
            originalLine: isset($position['old_line']) ? (int) $position['old_line'] : null,
        );
    }

    /**
     * @param array<string, mixed> $note
     */
    protected function gitLabDate(array $note): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) ($note['created_at'] ?? 'now'));
    }

    protected function gitLabAuthor(mixed $author): ?string
    {
        if (! is_array($author)) {
            return null;
        }

        return isset($author['username']) ? (string) $author['username'] : null;
    }

    protected function conversationDate(PullRequestFeedbackConversation $conversation): int
    {
        $date = $conversation->comments[0]->date ?? new \DateTimeImmutable();

        return $date->getTimestamp();
    }

    protected function hasNextPage(ResponseInterface $response, int $currentPage): bool
    {
        $headers = $response->getHeaders();

        if (isset($headers['x-total-pages'])) {
            /** @var string|string[] $totalPagesValue */
            $totalPagesValue = $headers['x-total-pages'];
            $totalPages = is_array($totalPagesValue) ? (int) $totalPagesValue[0] : (int) $totalPagesValue;

            return $currentPage < $totalPages;
        }

        if (isset($headers['x-next-page'])) {
            /** @var string|string[] $nextPageValue */
            $nextPageValue = $headers['x-next-page'];
            $nextPage = is_array($nextPageValue) ? $nextPageValue[0] : $nextPageValue;

            return $nextPage !== '';
        }

        return false;
    }
}
