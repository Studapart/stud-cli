<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestComment;
use App\DTO\PullRequestData;
use App\DTO\PullRequestFeedbackConversation;
use App\DTO\PullRequestFeedbackIds;
use App\Exception\PullRequestAssignmentException;

class GithubGitHostingAdapter implements GitHostingPort
{
    private const COMMENTS_PAGE_SIZE = 50;
    private const COMMENTS_MAX_PAGES = 1;
    private const COMMENTS_LIMIT = self::COMMENTS_PAGE_SIZE * self::COMMENTS_MAX_PAGES;
    private const PROVIDER_GITHUB = 'github';

    private readonly string $owner;
    private readonly string $repo;

    public function __construct(
        private readonly GithubApiClient $apiClient,
        private readonly GithubPullRequestCommentMapper $commentMapper = new GithubPullRequestCommentMapper(),
    ) {
        $this->owner = $apiClient->getOwner();
        $this->repo = $apiClient->getRepo();
    }

    /**
     * @return array<string, mixed>
     */
    public function createPullRequest(PullRequestData $prData): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls";
        $payload = [
            'title' => $prData->title,
            'head' => $prData->head,
            'base' => $prData->base,
            'body' => $prData->body,
            'draft' => $prData->draft,
        ];

        $createdPullRequest = $this->apiClient->request('POST', $apiUrl, 'Failed to create pull request.', ['json' => $payload])->toArray();
        if ($prData->assignToAuthor) {
            try {
                $this->assignPullRequestToAuthor($createdPullRequest);
            } catch (\Throwable $e) {
                throw new PullRequestAssignmentException(
                    $e->getMessage(),
                    (string) ($createdPullRequest['html_url'] ?? ''),
                    $e
                );
            }
        }

        return $createdPullRequest;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuthenticatedUser(): array
    {
        return $this->apiClient->request('GET', '/user', 'Failed to identify authenticated GitHub user.')->toArray();
    }

    /**
     * @param array<string, mixed> $prData
     */
    public function assignPullRequestToAuthor(array $prData): void
    {
        $number = isset($prData['number']) ? (int) $prData['number'] : 0;
        if ($number <= 0) {
            throw new \RuntimeException('Cannot assign pull request because its number is missing.');
        }

        $user = $this->getAuthenticatedUser();
        $login = isset($user['login']) ? (string) $user['login'] : '';
        if ($login === '') {
            throw new \RuntimeException('Cannot assign pull request because the authenticated GitHub login is missing.');
        }

        $apiUrl = "/repos/{$this->owner}/{$this->repo}/issues/{$number}/assignees";
        $assignedUsers = $this->apiClient->request(
            'POST',
            $apiUrl,
            "Failed to assign pull request #{$number} to {$login}.",
            ['json' => ['assignees' => [$login]]]
        )->toArray();

        if (! $this->hasAssignedGithubUser($assignedUsers, $login)) {
            throw new \RuntimeException("GitHub did not confirm '{$login}' as an assignee for pull request #{$number}.");
        }
    }

    /**
     * @param array<int, array<string, mixed>>|array<string, mixed> $assignedUsers
     */
    protected function hasAssignedGithubUser(array $assignedUsers, string $login): bool
    {
        foreach ($assignedUsers as $assignedUser) {
            if (is_array($assignedUser) && ($assignedUser['login'] ?? null) === $login) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLatestRelease(): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/releases/latest";

        return $this->apiClient->request('GET', $apiUrl, 'Failed to get latest release.')->toArray();
    }

    public function getChangelogContent(string $tag): string
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/contents/CHANGELOG.md?" . http_build_query(['ref' => $tag]);
        $content = $this->apiClient->request('GET', $apiUrl, 'Failed to get changelog content.')->toArray();

        // GitHub API returns base64 encoded content
        if (isset($content['content']) && isset($content['encoding']) && $content['encoding'] === 'base64') {
            $decoded = base64_decode($content['content'], true);
            if ($decoded === false) {
                throw new \RuntimeException('Unable to decode base64 content from GitHub API');
            }

            return $decoded;
        }

        throw new \RuntimeException('Unable to decode CHANGELOG.md content from GitHub API');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLabels(): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/labels";

        return $this->apiClient->request('GET', $apiUrl, 'Failed to get labels.')->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function createLabel(string $name, string $color, ?string $description = null): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/labels";
        $payload = ['name' => $name, 'color' => $color];
        if ($description !== null) {
            $payload['description'] = $description;
        }

        return $this->apiClient->request('POST', $apiUrl, "Failed to create label '{$name}'.", ['json' => $payload])->toArray();
    }

    /**
     * @param array<string> $labels
     */
    public function addLabelsToPullRequest(int $issueNumber, array $labels): void
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/labels";
        $this->apiClient->request('POST', $apiUrl, "Failed to add labels to pull request #{$issueNumber}.", ['json' => $labels]);
    }

    /**
     * Finds a pull request by branch head.
     *
     * @param string $head The branch head in format "owner:branch" or just "branch"
     * @param string $state The PR state: 'open', 'closed', or 'all' (default: 'open')
     * @return array<string, mixed>|null The PR data or null if not found
     */
    public function findPullRequestByBranch(string $head, string $state = 'open'): ?array
    {
        if ($state === 'all') {
            return $this->findPullRequestByBranchInternal($head, 'open')
                ?? $this->findPullRequestByBranchInternal($head, 'closed');
        }

        return $this->findPullRequestByBranchInternal($head, $state);
    }

    /**
     * @param string $head The branch head
     * @param string $state The PR state: 'open' or 'closed'
     * @return array<string, mixed>|null The PR data or null if not found
     */
    protected function findPullRequestByBranchInternal(string $head, string $state): ?array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls?" . http_build_query(['head' => $head, 'state' => $state]);
        $pulls = $this->apiClient->request('GET', $apiUrl, 'Failed to find pull request by branch.')->toArray();

        return ! empty($pulls) ? $pulls[0] : null;
    }

    /**
     * Finds a pull request by branch name (constructs owner:branch format automatically).
     *
     * @param string $branchName The branch name (without remote prefix)
     * @param string $state The PR state: 'open', 'closed', or 'all' (default: 'all')
     * @return array<string, mixed>|null The PR data or null if not found
     */
    public function findPullRequestByBranchName(string $branchName, string $state = 'all'): ?array
    {
        return $this->findPullRequestByBranch("{$this->owner}:{$branchName}", $state);
    }

    /**
     * Fetches all pull requests for the repository.
     *
     * @param string $state The PR state: 'open', 'closed', or 'all' (default: 'all')
     * @return array<int, array<string, mixed>> Array of PR data arrays
     */
    public function getAllPullRequests(string $state = 'all'): array
    {
        if ($state === 'all') {
            return array_merge(
                $this->getAllPullRequestsByState('open'),
                $this->getAllPullRequestsByState('closed')
            );
        }

        return $this->getAllPullRequestsByState($state);
    }

    /**
     * Fetches all pull requests for a specific state with pagination support.
     *
     * @param string $state The PR state: 'open' or 'closed'
     * @return array<int, array<string, mixed>> Array of PR data arrays
     */
    protected function getAllPullRequestsByState(string $state): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls?" . http_build_query(['state' => $state, 'per_page' => 100]);

        return $this->apiClient->getAllPages($apiUrl, 'Failed to get all pull requests.');
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $pullNumber, bool $draft): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls/{$pullNumber}";

        return $this->apiClient->request('PATCH', $apiUrl, "Failed to update pull request #{$pullNumber}.", [
            'json' => ['draft' => $draft],
        ])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function createComment(int $issueNumber, string $body): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments";

        return $this->apiClient->request('POST', $apiUrl, "Failed to create comment on issue #{$issueNumber}.", [
            'json' => ['body' => $body],
        ])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function replyToPullRequestFeedback(int $pullNumber, PullRequestFeedbackIds $targetIds, string $body): array
    {
        $threadId = $this->githubThreadId($targetIds, 'reply');

        return $this->apiClient->graphql(
            'mutation ReplyToReviewThread($threadId: ID!, $body: String!) {
  addPullRequestReviewThreadReply(input: { pullRequestReviewThreadId: $threadId, body: $body }) {
    comment { id databaseId body createdAt }
  }
}',
            ['threadId' => $threadId, 'body' => $body],
            "Failed to reply to review thread on pull request #{$pullNumber}."
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePullRequestFeedback(int $pullNumber, PullRequestFeedbackIds $targetIds): array
    {
        $threadId = $this->githubThreadId($targetIds, 'resolve');

        return $this->apiClient->graphql(
            'mutation ResolveReviewThread($threadId: ID!) {
  resolveReviewThread(input: { threadId: $threadId }) {
    thread { id isResolved }
  }
}',
            ['threadId' => $threadId],
            "Failed to resolve review thread on pull request #{$pullNumber}."
        );
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestComments(int $issueNumber): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE, 'sort' => 'created', 'direction' => 'desc']);

        $data = $this->apiClient->request('GET', $apiUrl, "Failed to get comments for issue #{$issueNumber}.")->toArray();

        return $this->commentMapper->mapComments($data, self::COMMENTS_LIMIT);
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestReviewComments(int $pullNumber): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls/{$pullNumber}/comments?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE]);

        $data = $this->apiClient->request('GET', $apiUrl, "Failed to get review comments for pull request #{$pullNumber}.")->toArray();

        return $this->commentMapper->mapComments($data, self::COMMENTS_LIMIT);
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestReviews(int $pullNumber): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls/{$pullNumber}/reviews?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE]);

        $data = $this->apiClient->request('GET', $apiUrl, "Failed to get reviews for pull request #{$pullNumber}.")->toArray();

        return $this->commentMapper->mapReviews($data, self::COMMENTS_LIMIT);
    }

    /**
     * @return PullRequestFeedbackConversation[]
     */
    public function getPullRequestFeedbackConversations(int $pullNumber): array
    {
        return (new GithubConversationProvider(
            $this->owner,
            $this->repo,
            \Closure::fromCallable([$this->apiClient, 'request'])
        ))->getPullRequestFeedbackConversations($pullNumber);
    }

    protected function githubThreadId(PullRequestFeedbackIds $targetIds, string $action): string
    {
        if ($targetIds->provider !== self::PROVIDER_GITHUB || $targetIds->threadId === null) {
            throw new \RuntimeException("Cannot {$action} this feedback target on GitHub because its review thread id is missing.");
        }

        return $targetIds->threadId;
    }
}
