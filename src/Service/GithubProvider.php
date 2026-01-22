<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestData;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GithubProvider
{
    public function __construct(
        private readonly string $token,
        private readonly string $owner,
        private readonly string $repo,
        private ?HttpClientInterface $client = null,
    ) {
        $this->client = $client ?? HttpClient::createForBaseUri('https://api.github.com', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
        ]);
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

        $response = $this->client->request('POST', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 201) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'POST %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }

        return $response->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getLatestRelease(): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/releases/latest";

        $response = $this->client->request('GET', $apiUrl);

        if ($response->getStatusCode() !== 200) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'GET %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }

        return $response->toArray();
    }

    public function getChangelogContent(string $tag): string
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/contents/CHANGELOG.md";
        $queryParams = http_build_query(['ref' => $tag]);
        $apiUrl .= '?' . $queryParams;

        $response = $this->client->request('GET', $apiUrl);

        if ($response->getStatusCode() !== 200) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'GET %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }

        $content = $response->toArray();

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

        $response = $this->client->request('GET', $apiUrl);

        if ($response->getStatusCode() !== 200) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'GET %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }

        return $response->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function createLabel(string $name, string $color, ?string $description = null): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/labels";
        $payload = [
            'name' => $name,
            'color' => $color,
        ];

        if ($description !== null) {
            $payload['description'] = $description;
        }

        $response = $this->client->request('POST', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 201) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'POST %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }

        return $response->toArray();
    }

    /**
     * @param array<string> $labels
     */
    public function addLabelsToPullRequest(int $issueNumber, array $labels): void
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/labels";
        $payload = $labels;

        $response = $this->client->request('POST', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 200) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'POST %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }
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
        // If state is 'all', we need to check both 'open' and 'closed'
        if ($state === 'all') {
            $openPr = $this->findPullRequestByBranchInternal($head, 'open');
            if ($openPr !== null) {
                return $openPr;
            }

            return $this->findPullRequestByBranchInternal($head, 'closed');
        }

        return $this->findPullRequestByBranchInternal($head, $state);
    }

    /**
     * Internal method to find PR by branch (avoids recursion).
     *
     * @param string $head The branch head
     * @param string $state The PR state: 'open' or 'closed'
     * @return array<string, mixed>|null The PR data or null if not found
     */
    protected function findPullRequestByBranchInternal(string $head, string $state): ?array
    {

        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls";
        $queryParams = http_build_query(['head' => $head, 'state' => $state]);
        $apiUrl .= '?' . $queryParams;

        $response = $this->client->request('GET', $apiUrl);

        if ($response->getStatusCode() !== 200) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'GET %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }

        $pulls = $response->toArray();

        // Return the first PR if any exist
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
        $owner = $this->owner;
        $head = "{$owner}:{$branchName}";

        return $this->findPullRequestByBranch($head, $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $pullNumber, bool $draft): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls/{$pullNumber}";
        $payload = [
            'draft' => $draft,
        ];

        $response = $this->client->request('PATCH', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 200) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'PATCH %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }

        return $response->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function createComment(int $issueNumber, string $body): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments";
        $payload = [
            'body' => $body,
        ];

        $response = $this->client->request('POST', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 201) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'POST %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }

        return $response->toArray();
    }

    /**
     * Attempts to update the head branch of a pull request.
     * Note: GitHub API may not support changing PR head branch after creation.
     * This method will throw an exception if the API doesn't support this operation.
     *
     * @param int $pullNumber The pull request number
     * @param string $newHead The new head branch (format: owner:branch-name or just branch-name)
     * @return array<string, mixed> Updated PR data
     * @throws \RuntimeException If the API doesn't support this operation or returns an error
     */
    public function updatePullRequestHead(int $pullNumber, string $newHead): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls/{$pullNumber}";
        $payload = [
            'head' => $newHead,
        ];

        $response = $this->client->request('PATCH', $apiUrl, ['json' => $payload]);

        if ($response->getStatusCode() !== 200) {
            $fullUrl = "https://api.github.com{$apiUrl}";
            $errorMessage = sprintf(
                "GitHub API Error (Status: %d) when calling 'PATCH %s'.\nResponse: %s",
                $response->getStatusCode(),
                $fullUrl,
                $response->getContent(false)
            );

            throw new \RuntimeException($errorMessage);
        }

        return $response->toArray();
    }
}
