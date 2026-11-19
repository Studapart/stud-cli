<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestComment;
use App\DTO\PullRequestData;
use App\DTO\PullRequestFeedbackConversation;
use App\DTO\PullRequestFeedbackIds;
use App\Exception\PullRequestAssignmentException;

class GitLabGitHostingAdapter implements GitHostingPort
{
    private const COMMENTS_PAGE_SIZE = 50;
    private const COMMENTS_MAX_PAGES = 1;
    private const COMMENTS_LIMIT = self::COMMENTS_PAGE_SIZE * self::COMMENTS_MAX_PAGES;
    private const PROVIDER_GITLAB = 'gitlab';

    private readonly string $projectPath;

    public function __construct(
        private readonly GitLabApiClient $apiClient,
        private readonly GitLabMergeRequestMapper $mapper = new GitLabMergeRequestMapper(),
    ) {
        $this->projectPath = $apiClient->getProjectPath();
    }

    /**
     * @return array<string, mixed>
     */
    public function createPullRequest(PullRequestData $prData): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests";
        $payload = [
            'title' => $prData->title,
            'source_branch' => $this->extractBranchName($prData->head),
            'target_branch' => $prData->base,
            'description' => $prData->body,
            'work_in_progress' => $prData->draft,
        ];
        $assigneeId = null;
        if ($prData->assignToAuthor) {
            $assigneeId = $this->getAuthenticatedUserId();
            $payload['assignee_ids'] = [$assigneeId];
        }

        $mergeRequest = $this->apiClient->request('POST', $apiUrl, 'Failed to create merge request.', ['json' => $payload])->toArray();
        if ($assigneeId !== null && ! $this->hasAssignedGitLabUser($mergeRequest, $assigneeId)) {
            throw new PullRequestAssignmentException(
                "GitLab did not confirm user '{$assigneeId}' as an assignee for the created merge request.",
                (string) ($mergeRequest['web_url'] ?? '')
            );
        }

        return $this->mapper->normalizeMergeRequestData($mergeRequest);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuthenticatedUser(): array
    {
        return $this->apiClient->request('GET', '/user', 'Failed to identify authenticated GitLab user.')->toArray();
    }

    /**
     * @param array<string, mixed> $prData
     */
    public function assignPullRequestToAuthor(array $prData): void
    {
        $number = isset($prData['number']) ? (int) $prData['number'] : 0;
        if ($number <= 0) {
            throw new \RuntimeException('Cannot assign merge request because its number is missing.');
        }

        $userId = $this->getAuthenticatedUserId();
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$number}";
        $updatedMergeRequest = $this->apiClient->request(
            'PUT',
            $apiUrl,
            "Failed to assign merge request #{$number} to authenticated user.",
            ['json' => ['assignee_ids' => [$userId]]]
        )->toArray();

        if (! $this->hasAssignedGitLabUser($updatedMergeRequest, $userId)) {
            throw new \RuntimeException("GitLab did not confirm user '{$userId}' as an assignee for merge request #{$number}.");
        }
    }

    protected function getAuthenticatedUserId(): int
    {
        $user = $this->getAuthenticatedUser();
        $id = isset($user['id']) ? (int) $user['id'] : 0;
        if ($id <= 0) {
            throw new \RuntimeException('Cannot assign merge request because the authenticated GitLab user id is missing.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $mergeRequestData
     */
    protected function hasAssignedGitLabUser(array $mergeRequestData, int $userId): bool
    {
        $assignees = $mergeRequestData['assignees'] ?? [];
        if (! is_array($assignees)) {
            return false;
        }

        foreach ($assignees as $assignee) {
            if (is_array($assignee) && (int) ($assignee['id'] ?? 0) === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds a merge request by branch head.
     *
     * @param string $head The branch head in format "owner:branch" or just "branch"
     * @param string $state The MR state: 'open', 'closed', or 'all' (default: 'open')
     * @return array<string, mixed>|null The MR data or null if not found
     */
    public function findPullRequestByBranch(string $head, string $state = 'open'): ?array
    {
        if ($state === 'all') {
            return $this->findMergeRequestByBranchInternal($head, 'opened')
                ?? $this->findMergeRequestByBranchInternal($head, 'closed');
        }

        return $this->findMergeRequestByBranchInternal($head, $this->mapper->mapStateToGitLab($state));
    }

    /**
     * @param string $head The branch head
     * @param string $state The MR state: 'opened', 'closed', or 'merged'
     * @return array<string, mixed>|null The MR data or null if not found
     */
    protected function findMergeRequestByBranchInternal(string $head, string $state): ?array
    {
        $branchName = $this->extractBranchName($head);
        $apiUrl = "/projects/{$this->projectPath}/merge_requests?"
            . http_build_query(['source_branch' => $branchName, 'state' => $state]);

        $mrs = $this->apiClient->request('GET', $apiUrl, 'Failed to find merge request by branch.')->toArray();

        return ! empty($mrs) ? $this->mapper->normalizeMergeRequestData($mrs[0]) : null;
    }

    /**
     * Finds a merge request by branch name.
     *
     * @param string $branchName The branch name (without remote prefix)
     * @param string $state The MR state: 'open', 'closed', or 'all' (default: 'all')
     * @return array<string, mixed>|null The MR data or null if not found
     */
    public function findPullRequestByBranchName(string $branchName, string $state = 'all'): ?array
    {
        return $this->findPullRequestByBranch($branchName, $state);
    }

    /**
     * @param array<string> $labels
     */
    public function addLabelsToPullRequest(int $issueNumber, array $labels): void
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$issueNumber}/labels";
        $this->apiClient->request('POST', $apiUrl, "Failed to add labels to merge request #{$issueNumber}.", [
            'json' => ['labels' => implode(',', $labels)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createComment(int $issueNumber, string $body): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$issueNumber}/notes";

        return $this->apiClient->request('POST', $apiUrl, "Failed to create comment on merge request #{$issueNumber}.", [
            'json' => ['body' => $body],
        ])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function replyToPullRequestFeedback(int $pullNumber, PullRequestFeedbackIds $targetIds, string $body): array
    {
        $discussionId = $this->gitLabDiscussionId($targetIds, 'reply');
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$pullNumber}/discussions/{$discussionId}/notes";

        return $this->apiClient->request('POST', $apiUrl, "Failed to reply to discussion on merge request #{$pullNumber}.", [
            'json' => ['body' => $body],
        ])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePullRequestFeedback(int $pullNumber, PullRequestFeedbackIds $targetIds): array
    {
        $discussionId = $this->gitLabDiscussionId($targetIds, 'resolve');
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$pullNumber}/discussions/{$discussionId}";

        return $this->apiClient->request('PUT', $apiUrl, "Failed to resolve discussion on merge request #{$pullNumber}.", [
            'json' => ['resolved' => true],
        ])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $pullNumber, bool $draft): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$pullNumber}";

        return $this->mapper->normalizeMergeRequestData(
            $this->apiClient->request('PUT', $apiUrl, "Failed to update merge request #{$pullNumber}.", [
                'json' => ['work_in_progress' => $draft],
            ])->toArray()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLabels(): array
    {
        $apiUrl = "/projects/{$this->projectPath}/labels";

        return $this->apiClient->request('GET', $apiUrl, 'Failed to get labels.')->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function createLabel(string $name, string $color, ?string $description = null): array
    {
        $apiUrl = "/projects/{$this->projectPath}/labels";
        $payload = ['name' => $name, 'color' => $this->normalizeColor($color)];
        if ($description !== null) {
            $payload['description'] = $description;
        }

        return $this->apiClient->request('POST', $apiUrl, "Failed to create label '{$name}'.", ['json' => $payload])->toArray();
    }

    /**
     * Fetches all merge requests for the repository.
     *
     * @param string $state The MR state: 'open', 'closed', or 'all' (default: 'all')
     * @return array<int, array<string, mixed>> Array of MR data arrays
     */
    public function getAllPullRequests(string $state = 'all'): array
    {
        if ($state === 'all') {
            return array_merge(
                $this->getAllMergeRequestsByState('opened'),
                $this->getAllMergeRequestsByState('closed'),
                $this->getAllMergeRequestsByState('merged')
            );
        }

        return $this->getAllMergeRequestsByState($this->mapper->mapStateToGitLab($state));
    }

    /**
     * Fetches all merge requests for a specific state with pagination support.
     *
     * @param string $state The MR state: 'opened', 'closed', or 'merged'
     * @return array<int, array<string, mixed>> Array of MR data arrays
     */
    protected function getAllMergeRequestsByState(string $state): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests?"
            . http_build_query(['state' => $state, 'per_page' => 100]);

        return array_map(
            fn (array $mr): array => $this->mapper->normalizeMergeRequestData($mr),
            $this->apiClient->getAllPages($apiUrl, 'Failed to get all merge requests.')
        );
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestComments(int $issueNumber): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$issueNumber}/notes?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE, 'order_by' => 'created_at', 'sort' => 'desc']);

        $data = $this->apiClient->request('GET', $apiUrl, "Failed to get comments for merge request #{$issueNumber}.")->toArray();

        return $this->mapper->mapNotes($data, self::COMMENTS_LIMIT);
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestReviewComments(int $pullNumber): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$pullNumber}/discussions?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE]);

        $discussions = $this->apiClient->request('GET', $apiUrl, "Failed to get review comments for merge request #{$pullNumber}.")->toArray();

        return $this->mapper->mapDiscussions($discussions, self::COMMENTS_LIMIT);
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestReviews(int $pullNumber): array
    {
        return [];
    }

    /**
     * @return PullRequestFeedbackConversation[]
     */
    public function getPullRequestFeedbackConversations(int $pullNumber): array
    {
        return (new GitLabConversationProvider(
            $this->projectPath,
            \Closure::fromCallable([$this->apiClient, 'request'])
        ))->getPullRequestFeedbackConversations($pullNumber);
    }

    protected function gitLabDiscussionId(PullRequestFeedbackIds $targetIds, string $action): string
    {
        if ($targetIds->provider !== self::PROVIDER_GITLAB || $targetIds->discussionId === null) {
            throw new \RuntimeException("Cannot {$action} this feedback target on GitLab because its discussion id is missing.");
        }

        return rawurlencode($targetIds->discussionId);
    }

    /**
     * Extracts branch name from "owner:branch" format or returns branch name as-is.
     */
    protected function extractBranchName(string $head): string
    {
        if (str_contains($head, ':')) {
            return explode(':', $head, 2)[1];
        }

        return $head;
    }

    /**
     * Normalizes color format (removes # if present, GitLab expects format without #).
     */
    protected function normalizeColor(string $color): string
    {
        return ltrim($color, '#');
    }
}
