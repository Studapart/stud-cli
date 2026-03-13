<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestComment;
use App\DTO\PullRequestData;
use App\Exception\ApiException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GitLabProvider implements GitProviderInterface
{
    private const COMMENTS_PAGE_SIZE = 50;
    private const COMMENTS_MAX_PAGES = 1;

    private readonly string $baseUrl;
    private readonly string $projectPath;

    public function __construct(
        private readonly string $token,
        private readonly string $owner,
        private readonly string $repo,
        private readonly ?string $instanceUrl = null,
        private ?HttpClientInterface $client = null,
    ) {
        $this->baseUrl = $this->instanceUrl ?? 'https://gitlab.com';
        // For nested groups, owner may contain slashes (e.g., "group/subgroup")
        $this->projectPath = urlencode("{$this->owner}/{$this->repo}");
    }

    /**
     * Gets the HTTP client, creating it if it doesn't exist.
     */
    protected function getClient(): HttpClientInterface
    {
        if ($this->client === null) {
            $this->client = HttpClient::createForBaseUri("{$this->baseUrl}/api/v4", [
                'headers' => [
                    'PRIVATE-TOKEN' => $this->token,
                    'Content-Type' => 'application/json',
                ],
            ]);
        }

        return $this->client;
    }

    /**
     * Sends an API request and throws on non-2xx response.
     *
     * @param array<string, mixed> $options
     * @throws ApiException When the response status is not 2xx
     */
    protected function apiRequest(string $method, string $apiUrl, string $errorMessage, array $options = []): ResponseInterface
    {
        $response = $this->getClient()->request($method, $apiUrl, $options);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new ApiException(
                $errorMessage,
                $this->extractTechnicalDetails($response, $method, $apiUrl),
                $response->getStatusCode()
            );
        }

        return $response;
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

        return $this->normalizeMergeRequestData(
            $this->apiRequest('POST', $apiUrl, 'Failed to create merge request.', ['json' => $payload])->toArray()
        );
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

        return $this->findMergeRequestByBranchInternal($head, $this->mapStateToGitLab($state));
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

        $mrs = $this->apiRequest('GET', $apiUrl, 'Failed to find merge request by branch.')->toArray();

        return ! empty($mrs) ? $this->normalizeMergeRequestData($mrs[0]) : null;
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
        $this->apiRequest('POST', $apiUrl, "Failed to add labels to merge request #{$issueNumber}.", [
            'json' => ['labels' => implode(',', $labels)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createComment(int $issueNumber, string $body): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$issueNumber}/notes";

        return $this->apiRequest('POST', $apiUrl, "Failed to create comment on merge request #{$issueNumber}.", [
            'json' => ['body' => $body],
        ])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $pullNumber, bool $draft): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$pullNumber}";

        return $this->normalizeMergeRequestData(
            $this->apiRequest('PUT', $apiUrl, "Failed to update merge request #{$pullNumber}.", [
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

        return $this->apiRequest('GET', $apiUrl, 'Failed to get labels.')->toArray();
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

        return $this->apiRequest('POST', $apiUrl, "Failed to create label '{$name}'.", ['json' => $payload])->toArray();
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

        return $this->getAllMergeRequestsByState($this->mapStateToGitLab($state));
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
        $allMrs = [];
        $page = 1;

        while (true) {
            $pageUrl = $apiUrl . '&page=' . $page;
            $response = $this->apiRequest('GET', $pageUrl, 'Failed to get all merge requests.');
            $mrs = $response->toArray();
            $normalizedMrs = array_map(fn (array $mr) => $this->normalizeMergeRequestData($mr), $mrs);
            $allMrs = array_merge($allMrs, $normalizedMrs);

            if (empty($mrs) || ! $this->hasNextPage($response, $page)) {
                break;
            }

            ++$page;
        }

        return $allMrs;
    }

    /**
     * Checks if there is a next page based on GitLab pagination headers.
     */
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

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestComments(int $issueNumber): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$issueNumber}/notes?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE, 'order_by' => 'created_at', 'sort' => 'desc']);

        $data = $this->apiRequest('GET', $apiUrl, "Failed to get comments for merge request #{$issueNumber}.")->toArray();
        $comments = [];
        $count = 0;
        foreach ($data as $row) {
            if ($count >= self::COMMENTS_PAGE_SIZE * self::COMMENTS_MAX_PAGES) {
                // Pagination cap for notes
                // @codeCoverageIgnoreStart
                break;
                // @codeCoverageIgnoreEnd
            }
            $comments[] = $this->mapNoteToDto($row);
            ++$count;
        }

        return array_reverse($comments);
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestReviewComments(int $pullNumber): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$pullNumber}/discussions?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE]);

        $discussions = $this->apiRequest('GET', $apiUrl, "Failed to get review comments for merge request #{$pullNumber}.")->toArray();

        return array_reverse($this->buildReviewCommentsFromDiscussions($discussions));
    }

    /**
     * @param array<int, array<string, mixed>> $discussions
     * @return PullRequestComment[]
     */
    protected function buildReviewCommentsFromDiscussions(array $discussions): array
    {
        $comments = [];
        $count = 0;
        $cap = self::COMMENTS_PAGE_SIZE * self::COMMENTS_MAX_PAGES;
        foreach ($discussions as $discussion) {
            if ($count >= $cap) {
                break;
            }
            $position = $discussion['position'] ?? null;
            if (! is_array($position)) {
                continue;
            }
            [$path, $line] = $this->extractPathAndLineFromPosition($position);
            $notes = $discussion['notes'] ?? [];
            foreach ($notes as $note) {
                if ($count >= $cap) {
                    break;
                }
                $comments[] = $this->mapNoteToDto($note, $path, $line);
                ++$count;
            }
        }

        return $comments;
    }

    /**
     * @param array<string, mixed> $position
     * @return array{0: ?string, 1: ?int}
     */
    protected function extractPathAndLineFromPosition(array $position): array
    {
        $path = isset($position['new_path']) ? (string) $position['new_path'] : (isset($position['old_path']) ? (string) $position['old_path'] : null);
        $line = isset($position['new_line']) ? (int) $position['new_line'] : (isset($position['old_line']) ? (int) $position['old_line'] : null);

        return [$path, $line];
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestReviews(int $pullNumber): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function mapNoteToDto(array $row, ?string $path = null, ?int $line = null): PullRequestComment
    {
        $authorData = $row['author'] ?? [];
        $author = is_array($authorData) && isset($authorData['username']) ? (string) $authorData['username'] : 'unknown';
        $createdAt = isset($row['created_at']) ? new \DateTimeImmutable((string) $row['created_at']) : new \DateTimeImmutable();
        $body = isset($row['body']) ? (string) $row['body'] : '';

        return new PullRequestComment($author, $createdAt, $body, $path, $line);
    }

    /**
     * Normalizes GitLab merge request data to match GitHub pull request format.
     *
     * @param array<string, mixed> $mrData GitLab MR data
     * @return array<string, mixed> Normalized PR data
     */
    protected function normalizeMergeRequestData(array $mrData): array
    {
        return [
            'number' => $mrData['iid'] ?? $mrData['id'] ?? 0,
            'iid' => $mrData['iid'] ?? null,
            'id' => $mrData['id'] ?? null,
            'title' => $mrData['title'] ?? '',
            'head' => ['ref' => $mrData['source_branch'] ?? ''],
            'base' => ['ref' => $mrData['target_branch'] ?? ''],
            'draft' => $mrData['work_in_progress'] ?? false,
            'state' => $this->mapStateFromGitLab($mrData['state'] ?? 'opened'),
            'body' => $mrData['description'] ?? '',
            'html_url' => $mrData['web_url'] ?? '',
            '_gitlab_data' => $mrData,
        ];
    }

    /**
     * Maps GitHub state to GitLab state.
     */
    protected function mapStateToGitLab(string $githubState): string
    {
        return match ($githubState) {
            'open' => 'opened',
            'closed' => 'closed',
            default => $githubState,
        };
    }

    /**
     * Maps GitLab state to GitHub state.
     */
    protected function mapStateFromGitLab(string $gitlabState): string
    {
        return match ($gitlabState) {
            'opened' => 'open',
            'closed', 'merged' => 'closed',
            default => $gitlabState,
        };
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

    /**
     * Extracts technical details from an HTTP response for error reporting.
     * Truncates response body to 500 characters to avoid overwhelming output.
     */
    protected function extractTechnicalDetails(ResponseInterface $response, string $method, string $apiUrl): string
    {
        $statusCode = $response->getStatusCode();
        $fullUrl = "{$this->baseUrl}/api/v4{$apiUrl}";
        $responseBody = 'No response body';

        try {
            $content = $response->getContent(false);
            if (! empty($content)) {
                $responseBody = mb_strlen($content) > 500
                    ? mb_substr($content, 0, 500) . '... (truncated)'
                    : $content;
            }
        } catch (\Exception $e) {
            $responseBody = 'Unable to read response body: ' . $e->getMessage();
        }

        return sprintf(
            "GitLab API Error (Status: %d) when calling '%s %s'.\nOwner: %s\nRepo: %s\nProject Path: %s\nFull URL: %s\nResponse: %s",
            $statusCode,
            $method,
            $apiUrl,
            $this->owner,
            $this->repo,
            $this->projectPath,
            $fullUrl,
            $responseBody
        );
    }
}
