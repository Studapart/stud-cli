<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestData;
use App\Exception\ApiException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitLabProvider implements GitProviderInterface
{
    private readonly string $baseUrl;
    private readonly string $projectPath;

    public function __construct(
        private readonly string $token,
        private readonly string $owner,
        private readonly string $repo,
        private readonly ?string $instanceUrl = null,
        private ?HttpClientInterface $client = null,
    ) {
        // Use custom instance URL or default to gitlab.com
        $this->baseUrl = $this->instanceUrl ?? 'https://gitlab.com';
        // GitLab uses URL-encoded project path: owner%2Frepo
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

        $response = $this->getClient()->request('POST', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 201) {
            $technicalDetails = $this->extractTechnicalDetails($response, 'POST', $apiUrl);

            throw new ApiException(
                'Failed to create merge request.',
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        return $this->normalizeMergeRequestData($response->toArray());
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
        // If state is 'all', we need to check both 'opened' and 'closed'
        if ($state === 'all') {
            $openMr = $this->findMergeRequestByBranchInternal($head, 'opened');
            if ($openMr !== null) {
                return $openMr;
            }

            return $this->findMergeRequestByBranchInternal($head, 'closed');
        }

        // Map GitHub states to GitLab states
        $gitlabState = $this->mapStateToGitLab($state);

        return $this->findMergeRequestByBranchInternal($head, $gitlabState);
    }

    /**
     * Internal method to find MR by branch (avoids recursion).
     *
     * @param string $head The branch head
     * @param string $state The MR state: 'opened', 'closed', or 'merged'
     * @return array<string, mixed>|null The MR data or null if not found
     */
    protected function findMergeRequestByBranchInternal(string $head, string $state): ?array
    {
        $branchName = $this->extractBranchName($head);
        $apiUrl = "/projects/{$this->projectPath}/merge_requests";
        $queryParams = http_build_query(['source_branch' => $branchName, 'state' => $state]);
        $apiUrl .= '?' . $queryParams;

        $response = $this->getClient()->request('GET', $apiUrl);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response, 'GET', $apiUrl);

            throw new ApiException(
                'Failed to find merge request by branch.',
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        $mrs = $response->toArray();

        // Return the first MR if any exist, normalized to match GitHub format
        return ! empty($mrs) ? $this->normalizeMergeRequestData($mrs[0]) : null;
    }

    /**
     * Finds a merge request by branch name (constructs owner:branch format automatically).
     *
     * @param string $branchName The branch name (without remote prefix)
     * @param string $state The MR state: 'open', 'closed', or 'all' (default: 'all')
     * @return array<string, mixed>|null The MR data or null if not found
     */
    public function findPullRequestByBranchName(string $branchName, string $state = 'all'): ?array
    {
        // GitLab doesn't need owner prefix, just use branch name directly
        return $this->findPullRequestByBranch($branchName, $state);
    }

    /**
     * @param array<string> $labels
     */
    public function addLabelsToPullRequest(int $issueNumber, array $labels): void
    {
        // GitLab uses iid (internal ID) instead of sequential number
        $iid = $this->getIidFromNumber($issueNumber);
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$iid}/labels";
        // GitLab accepts labels as comma-separated string in the labels parameter
        $payload = [
            'labels' => implode(',', $labels),
        ];

        $response = $this->getClient()->request('POST', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response, 'POST', $apiUrl);

            throw new ApiException(
                "Failed to add labels to merge request #{$issueNumber}.",
                $technicalDetails,
                $response->getStatusCode()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function createComment(int $issueNumber, string $body): array
    {
        // GitLab uses iid (internal ID) instead of sequential number
        $iid = $this->getIidFromNumber($issueNumber);
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$iid}/notes";
        $payload = [
            'body' => $body,
        ];

        $response = $this->getClient()->request('POST', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 201) {
            $technicalDetails = $this->extractTechnicalDetails($response, 'POST', $apiUrl);

            throw new ApiException(
                "Failed to create comment on merge request #{$issueNumber}.",
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        return $response->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $pullNumber, bool $draft): array
    {
        // GitLab uses iid (internal ID) instead of sequential number
        $iid = $this->getIidFromNumber($pullNumber);
        $apiUrl = "/projects/{$this->projectPath}/merge_requests/{$iid}";
        $payload = [
            'work_in_progress' => $draft,
        ];

        $response = $this->getClient()->request('PUT', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response, 'PUT', $apiUrl);

            throw new ApiException(
                "Failed to update merge request #{$pullNumber}.",
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        return $this->normalizeMergeRequestData($response->toArray());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLabels(): array
    {
        $apiUrl = "/projects/{$this->projectPath}/labels";

        $response = $this->getClient()->request('GET', $apiUrl);

        if ($response->getStatusCode() !== 200) {
            $technicalDetails = $this->extractTechnicalDetails($response, 'GET', $apiUrl);

            throw new ApiException(
                'Failed to get labels.',
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        return $response->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function createLabel(string $name, string $color, ?string $description = null): array
    {
        $apiUrl = "/projects/{$this->projectPath}/labels";
        $payload = [
            'name' => $name,
            'color' => $this->normalizeColor($color),
        ];

        if ($description !== null) {
            $payload['description'] = $description;
        }

        $response = $this->getClient()->request('POST', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 201) {
            $technicalDetails = $this->extractTechnicalDetails($response, 'POST', $apiUrl);

            throw new ApiException(
                "Failed to create label '{$name}'.",
                $technicalDetails,
                $response->getStatusCode()
            );
        }

        return $response->toArray();
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
            $openedMrs = $this->getAllMergeRequestsByState('opened');
            $closedMrs = $this->getAllMergeRequestsByState('closed');
            $mergedMrs = $this->getAllMergeRequestsByState('merged');

            return array_merge($openedMrs, $closedMrs, $mergedMrs);
        }

        $gitlabState = $this->mapStateToGitLab($state);

        return $this->getAllMergeRequestsByState($gitlabState);
    }

    /**
     * Fetches all merge requests for a specific state with pagination support.
     *
     * @param string $state The MR state: 'opened', 'closed', or 'merged'
     * @return array<int, array<string, mixed>> Array of MR data arrays
     */
    protected function getAllMergeRequestsByState(string $state): array
    {
        $apiUrl = "/projects/{$this->projectPath}/merge_requests";
        $queryParams = http_build_query(['state' => $state, 'per_page' => 100]);
        $apiUrl .= '?' . $queryParams;

        $allMrs = [];
        $page = 1;

        while (true) {
            $pageUrl = $apiUrl . '&page=' . $page;
            $response = $this->getClient()->request('GET', $pageUrl);

            if ($response->getStatusCode() !== 200) {
                $technicalDetails = $this->extractTechnicalDetails($response, 'GET', $pageUrl);

                throw new ApiException(
                    'Failed to get all merge requests.',
                    $technicalDetails,
                    $response->getStatusCode()
                );
            }

            $mrs = $response->toArray();
            // Normalize each MR to match GitHub format
            $normalizedMrs = array_map(fn (array $mr) => $this->normalizeMergeRequestData($mr), $mrs);
            $allMrs = array_merge($allMrs, $normalizedMrs);

            // Stop if no results
            if (empty($mrs)) {
                break;
            }

            // Check for pagination - GitLab uses X-Total-Pages header
            if (! $this->hasNextPage($response, $page)) {
                break;
            }

            // There's a next page, increment for next iteration
            ++$page;
        }

        return $allMrs;
    }

    /**
     * Checks if there is a next page based on GitLab pagination headers.
     *
     * @param \Symfony\Contracts\HttpClient\ResponseInterface $response The HTTP response
     * @param int $currentPage The current page number
     * @return bool True if there is a next page, false otherwise
     */
    protected function hasNextPage(\Symfony\Contracts\HttpClient\ResponseInterface $response, int $currentPage): bool
    {
        $headers = $response->getHeaders();

        // GitLab provides X-Total-Pages header
        if (isset($headers['x-total-pages'])) {
            /** @var string|string[] $totalPagesValue */
            $totalPagesValue = $headers['x-total-pages'];
            $totalPages = is_array($totalPagesValue) ? (int) $totalPagesValue[0] : (int) $totalPagesValue;

            return $currentPage < $totalPages;
        }

        // Fallback: check X-Next-Page header if available
        if (isset($headers['x-next-page'])) {
            /** @var string|string[] $nextPageValue */
            $nextPageValue = $headers['x-next-page'];
            $nextPage = is_array($nextPageValue) ? $nextPageValue[0] : $nextPageValue;

            return $nextPage !== '';
        }

        return false;
    }

    /**
     * Normalizes GitLab merge request data to match GitHub pull request format.
     * This ensures handlers can work with both providers without changes.
     *
     * @param array<string, mixed> $mrData GitLab MR data
     * @return array<string, mixed> Normalized PR data
     */
    protected function normalizeMergeRequestData(array $mrData): array
    {
        // Map GitLab fields to GitHub format
        return [
            'number' => $mrData['iid'] ?? $mrData['id'] ?? 0, // Use iid as number for compatibility
            'iid' => $mrData['iid'] ?? null, // Keep original iid
            'id' => $mrData['id'] ?? null, // Keep original id
            'title' => $mrData['title'] ?? '',
            'head' => [
                'ref' => $mrData['source_branch'] ?? '',
            ],
            'base' => [
                'ref' => $mrData['target_branch'] ?? '',
            ],
            'draft' => $mrData['work_in_progress'] ?? false,
            'state' => $this->mapStateFromGitLab($mrData['state'] ?? 'opened'),
            'body' => $mrData['description'] ?? '',
            'html_url' => $mrData['web_url'] ?? '',
            // Include original GitLab data for reference
            '_gitlab_data' => $mrData,
        ];
    }

    /**
     * Maps GitHub state to GitLab state.
     *
     * @param string $githubState GitHub state: 'open', 'closed', or 'all'
     * @return string GitLab state: 'opened', 'closed', 'merged', or 'all'
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
     *
     * @param string $gitlabState GitLab state: 'opened', 'closed', or 'merged'
     * @return string GitHub state: 'open' or 'closed'
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
     *
     * @param string $head Branch head in format "owner:branch" or just "branch"
     * @return string The branch name
     */
    protected function extractBranchName(string $head): string
    {
        // If head contains ':', extract the branch part
        if (str_contains($head, ':')) {
            $parts = explode(':', $head, 2);

            return $parts[1];
        }

        return $head;
    }

    /**
     * Gets the GitLab iid (internal ID) from a PR number.
     * For GitLab, the number passed by handlers is actually the iid.
     *
     * @param int $number The PR number (which is the iid in GitLab)
     * @return int The iid
     */
    protected function getIidFromNumber(int $number): int
    {
        // In GitLab, handlers will use iid as the "number"
        return $number;
    }

    /**
     * Normalizes color format (removes # if present, GitLab expects format without #).
     *
     * @param string $color Color in hex format (with or without #)
     * @return string Normalized color
     */
    protected function normalizeColor(string $color): string
    {
        return ltrim($color, '#');
    }

    /**
     * Extracts technical details from an HTTP response for error reporting.
     * Truncates response body to 500 characters to avoid overwhelming output.
     *
     * @param \Symfony\Contracts\HttpClient\ResponseInterface $response
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $apiUrl API endpoint URL
     * @return string Technical details including method, URL, status code and response body
     */
    protected function extractTechnicalDetails(\Symfony\Contracts\HttpClient\ResponseInterface $response, string $method, string $apiUrl): string
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
