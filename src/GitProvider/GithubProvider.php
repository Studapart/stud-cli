<?php

namespace App\GitProvider;

use Symfony\Component\HttpClient\HttpClient;

class GithubProvider
{
    public function __construct(
        private readonly string $token,
        private readonly string $owner,
        private readonly string $repo,
    ) {
    }

    public function createPullRequest(string $title, string $head, string $base, string $body): array
    {
        $client = HttpClient::createForBaseUri('https://api.github.com', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $apiUrl = "/repos/{$this->owner}/{$this->repo}/pulls";
        $payload = [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ];

        $response = $client->request('POST', $apiUrl, ['json' => $payload]);

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
