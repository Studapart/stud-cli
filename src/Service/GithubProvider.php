<?php

namespace App\Service;

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

    public function createPullRequest(string $title, string $head, string $base, string $body, bool $draft = false): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls";
        $payload = [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
            'draft' => $draft,
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
            return base64_decode($content['content'], true);
        }

        throw new \RuntimeException('Unable to decode CHANGELOG.md content from GitHub API');
    }

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

    public function findPullRequestByBranch(string $head): ?array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls";
        $queryParams = http_build_query(['head' => $head, 'state' => 'open']);
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
        return !empty($pulls) ? $pulls[0] : null;
    }

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
}
