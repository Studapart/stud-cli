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

    public function createPullRequest(string $title, string $head, string $base, string $body): array
    {
        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls";
        $payload = [
            'title' => $title,
            'head' => $head,
            'base' => $base,
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
