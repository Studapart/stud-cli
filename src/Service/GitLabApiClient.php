<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\HttpClientDefaults;
use App\DTO\GitLabProject;
use App\Exception\ApiException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GitLabApiClient
{
    private readonly string $baseUrl;
    private readonly string $projectPath;

    public function __construct(
        private readonly string $token,
        private readonly GitLabProject $project,
        private ?HttpClientInterface $client = null,
    ) {
        $this->baseUrl = $this->project->instanceUrl ?? 'https://gitlab.com';
        $this->projectPath = urlencode("{$this->project->owner}/{$this->project->repo}");
    }

    /**
     * URL-encoded "owner/repo" path used as the project id in API URLs.
     */
    public function getProjectPath(): string
    {
        return $this->projectPath;
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
     * Fetches every page of a paginated list endpoint, following GitLab pagination headers.
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

            if (empty($items) || ! $this->hasNextPage($response, $page)) {
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
            $this->client = HttpClient::createForBaseUri("{$this->baseUrl}/api/v4", HttpClientDefaults::withLimits([
                'headers' => [
                    'PRIVATE-TOKEN' => $this->token,
                    'Content-Type' => 'application/json',
                ],
            ]));
        }

        return $this->client;
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
            $this->project->owner,
            $this->project->repo,
            $this->projectPath,
            $fullUrl,
            $responseBody
        );
    }
}
