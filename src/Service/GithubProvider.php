<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestComment;
use App\DTO\PullRequestData;
use App\Exception\ApiException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GithubProvider implements GitProviderInterface
{
    private const COMMENTS_PAGE_SIZE = 50;
    private const COMMENTS_MAX_PAGES = 1;

    public function __construct(
        private readonly string $token,
        private readonly string $owner,
        private readonly string $repo,
        private ?HttpClientInterface $client = null,
    ) {
    }

    /**
     * Gets the HTTP client, creating it if it doesn't exist.
     */
    protected function getClient(): HttpClientInterface
    {
        if ($this->client === null) {
            $this->client = HttpClient::createForBaseUri('https://api.github.com', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/vnd.github.v3+json',
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
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls";
        $payload = [
            'title' => $prData->title,
            'head' => $prData->head,
            'base' => $prData->base,
            'body' => $prData->body,
            'draft' => $prData->draft,
        ];

        return $this->apiRequest('POST', $apiUrl, 'Failed to create pull request.', ['json' => $payload])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getLatestRelease(): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/releases/latest";

        return $this->apiRequest('GET', $apiUrl, 'Failed to get latest release.')->toArray();
    }

    public function getChangelogContent(string $tag): string
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/contents/CHANGELOG.md?" . http_build_query(['ref' => $tag]);
        $content = $this->apiRequest('GET', $apiUrl, 'Failed to get changelog content.')->toArray();

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

        return $this->apiRequest('GET', $apiUrl, 'Failed to get labels.')->toArray();
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

        return $this->apiRequest('POST', $apiUrl, "Failed to create label '{$name}'.", ['json' => $payload])->toArray();
    }

    /**
     * @param array<string> $labels
     */
    public function addLabelsToPullRequest(int $issueNumber, array $labels): void
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/labels";
        $this->apiRequest('POST', $apiUrl, "Failed to add labels to pull request #{$issueNumber}.", ['json' => $labels]);
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
        $pulls = $this->apiRequest('GET', $apiUrl, 'Failed to find pull request by branch.')->toArray();

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
        $allPrs = [];
        $page = 1;

        while (true) {
            $pageUrl = $apiUrl . '&page=' . $page;
            $response = $this->apiRequest('GET', $pageUrl, 'Failed to get all pull requests.');
            $prs = $response->toArray();
            $allPrs = array_merge($allPrs, $prs);

            if (empty($prs) || ! $this->hasNextPage($response)) {
                break;
            }

            ++$page;
        }

        return $allPrs;
    }

    /**
     * Checks if there is a next page based on the Link header.
     */
    protected function hasNextPage(ResponseInterface $response): bool
    {
        $headers = $response->getHeaders();
        if (! isset($headers['link']) || empty($headers['link'])) {
            return false;
        }

        /** @var string|string[] $linkHeaderValue */
        $linkHeaderValue = $headers['link'];
        $linkHeader = is_array($linkHeaderValue) ? $linkHeaderValue[0] : $linkHeaderValue;

        return str_contains($linkHeader, 'rel="next"');
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $pullNumber, bool $draft): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls/{$pullNumber}";

        return $this->apiRequest('PATCH', $apiUrl, "Failed to update pull request #{$pullNumber}.", [
            'json' => ['draft' => $draft],
        ])->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function createComment(int $issueNumber, string $body): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments";

        return $this->apiRequest('POST', $apiUrl, "Failed to create comment on issue #{$issueNumber}.", [
            'json' => ['body' => $body],
        ])->toArray();
    }

    /**
     * Attempts to update the head branch of a pull request.
     *
     * @param int $pullNumber The pull request number
     * @param string $newHead The new head branch (format: owner:branch-name or just branch-name)
     * @return array<string, mixed> Updated PR data
     * @throws \RuntimeException If the API doesn't support this operation or returns an error
     */
    public function updatePullRequestHead(int $pullNumber, string $newHead): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls/{$pullNumber}";

        return $this->apiRequest('PATCH', $apiUrl, "Failed to update pull request head for PR #{$pullNumber}.", [
            'json' => ['head' => $newHead],
        ])->toArray();
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestComments(int $issueNumber): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE, 'sort' => 'created', 'direction' => 'desc']);

        $data = $this->apiRequest('GET', $apiUrl, "Failed to get comments for issue #{$issueNumber}.")->toArray();
        $comments = [];
        foreach (array_slice($data, 0, self::COMMENTS_PAGE_SIZE * self::COMMENTS_MAX_PAGES) as $row) {
            $comments[] = $this->mapCommentToDto($row);
        }

        return array_reverse($comments);
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestReviewComments(int $pullNumber): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls/{$pullNumber}/comments?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE]);

        $data = $this->apiRequest('GET', $apiUrl, "Failed to get review comments for pull request #{$pullNumber}.")->toArray();
        $comments = [];
        foreach (array_slice($data, 0, self::COMMENTS_PAGE_SIZE * self::COMMENTS_MAX_PAGES) as $row) {
            $comments[] = $this->mapCommentToDto($row);
        }

        return array_reverse($comments);
    }

    /**
     * @return PullRequestComment[]
     */
    public function getPullRequestReviews(int $pullNumber): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls/{$pullNumber}/reviews?"
            . http_build_query(['per_page' => self::COMMENTS_PAGE_SIZE]);

        $data = $this->apiRequest('GET', $apiUrl, "Failed to get reviews for pull request #{$pullNumber}.")->toArray();
        $reviews = [];
        $cap = self::COMMENTS_PAGE_SIZE * self::COMMENTS_MAX_PAGES;
        foreach ($data as $row) {
            $dto = $this->mapReviewToDto($row);
            if ($dto !== null) {
                $reviews[] = $dto;
                if (count($reviews) >= $cap) {
                    break;
                }
            }
        }

        return array_reverse($reviews);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function mapReviewToDto(array $row): ?PullRequestComment
    {
        $body = isset($row['body']) ? trim((string) $row['body']) : '';
        if ($body === '') {
            return null;
        }
        $user = $row['user'] ?? [];
        $author = is_array($user) && isset($user['login']) ? (string) $user['login'] : 'unknown';
        $date = new \DateTimeImmutable((string) ($row['submitted_at'] ?? $row['created_at'] ?? 'now'));

        return new PullRequestComment($author, $date, $body, null, null);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function mapCommentToDto(array $row): PullRequestComment
    {
        $user = $row['user'] ?? [];
        $author = is_array($user) && isset($user['login']) ? (string) $user['login'] : 'unknown';
        $createdAt = isset($row['created_at']) ? new \DateTimeImmutable((string) $row['created_at']) : new \DateTimeImmutable();
        $body = isset($row['body']) ? (string) $row['body'] : '';
        $path = isset($row['path']) ? (string) $row['path'] : null;
        $line = isset($row['line']) ? (int) $row['line'] : null;

        return new PullRequestComment($author, $createdAt, $body, $path, $line);
    }

    /**
     * Extracts technical details from an HTTP response for error reporting.
     * Truncates response body to 500 characters to avoid overwhelming output.
     */
    protected function extractTechnicalDetails(ResponseInterface $response, string $method, string $apiUrl): string
    {
        $statusCode = $response->getStatusCode();
        $fullUrl = "https://api.github.com{$apiUrl}";
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
            "GitHub API Error (Status: %d) when calling '%s %s'.\nOwner: %s\nRepo: %s\nFull URL: %s\nResponse: %s",
            $statusCode,
            $method,
            $apiUrl,
            $this->owner,
            $this->repo,
            $fullUrl,
            $responseBody
        );
    }
}
