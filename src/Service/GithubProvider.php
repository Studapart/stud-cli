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
}
