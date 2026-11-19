<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\HttpClientDefaults;
use App\Exception\ApiException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GithubApiClient
{
    private const UNKNOWN_GRAPHQL_ERROR = 'Unknown GraphQL error';

    public function __construct(
        private readonly string $token,
        private readonly string $owner,
        private readonly string $repo,
        private ?HttpClientInterface $client = null,
    ) {
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getRepo(): string
    {
        return $this->repo;
    }

    /**
     * Sends an API request and throws on non-2xx response.
     *
     * @param array<string, mixed> $options
     * @throws ApiException When the response status is not 2xx
     */
    public function request(string $method, string $apiUrl, string $errorMessage, array $options = []): ResponseInterface
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
     * Sends a GraphQL request and throws when the payload reports errors.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables, string $errorMessage): array
    {
        $payload = $this->request('POST', '/graphql', $errorMessage, [
            'json' => ['query' => $query, 'variables' => $variables],
        ])->toArray(false);

        if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            $message = isset($payload['errors'][0]['message']) ? (string) $payload['errors'][0]['message'] : self::UNKNOWN_GRAPHQL_ERROR;

            throw new \RuntimeException($errorMessage . ' ' . $message);
        }

        return $payload;
    }

    /**
     * Fetches every page of a paginated list endpoint, following the Link header.
     *
     * @param string $apiUrl The list URL including a query string
     * @return array<int, array<string, mixed>>
     */
    public function getAllPages(string $apiUrl, string $errorMessage): array
    {
        $allItems = [];
        $page = 1;

        while (true) {
            $response = $this->request('GET', $apiUrl . '&page=' . $page, $errorMessage);
            $items = $response->toArray();
            $allItems = array_merge($allItems, $items);

            if (empty($items) || ! $this->hasNextPage($response)) {
                break;
            }

            ++$page;
        }

        return $allItems;
    }

    /**
     * Gets the HTTP client, creating it if it doesn't exist.
     */
    protected function getClient(): HttpClientInterface
    {
        if ($this->client === null) {
            $this->client = HttpClient::createForBaseUri('https://api.github.com', HttpClientDefaults::withLimits([
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/vnd.github.v3+json',
                    'Content-Type' => 'application/json',
                ],
            ]));
        }

        return $this->client;
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
