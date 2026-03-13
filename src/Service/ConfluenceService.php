<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ConfluenceService
{
    /** Relative to Confluence base URL (base must end with / so path is appended under /wiki). */
    private const API_PREFIX = 'api/v2';

    public function __construct(
        private readonly HttpClientInterface $client
    ) {
    }

    /**
     * List spaces visible to the current user.
     * Follows pagination (Link: next) so all spaces are returned.
     *
     * @return array<int, array{id: string, key: string, name: string}>
     */
    public function getSpaces(): array
    {
        $list = [];
        $url = self::API_PREFIX . '/spaces?limit=50';

        do {
            $response = $this->client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                throw new ApiException(
                    'Failed to fetch Confluence spaces.',
                    $this->extractTechnicalDetails($response),
                    $response->getStatusCode()
                );
            }

            $data = $response->toArray();
            $results = $data['results'] ?? [];

            foreach ($results as $item) {
                $list[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'key' => (string) ($item['key'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                ];
            }

            $url = $this->getNextPageUrl($response);
        } while ($url !== null);

        return $list;
    }

    /**
     * Parse Link header and return the full 'next' URL, or null if none.
     * Confluence v2 returns a full URL in the Link header for cursor pagination.
     */
    protected function getNextPageUrl(ResponseInterface $response): ?string
    {
        $linkHeader = $response->getHeaders(false)['link'] ?? [];
        $link = ($linkHeader[0] ?? '');
        if ($link === '') {
            return null;
        }
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m)) {
            $fullUrl = trim($m[1]);

            return $fullUrl !== '' ? $fullUrl : null;
        }

        return null;
    }

    /**
     * Get direct child pages of a page (for resolving "title already exists" by finding existing page to update).
     * Follows pagination via Link: next.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public function getDirectChildPages(string $parentPageId): array
    {
        $list = [];
        $url = self::API_PREFIX . '/pages/' . $parentPageId . '/direct-children?limit=50';

        do {
            $response = $this->client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                throw new ApiException(
                    'Failed to fetch child pages.',
                    $this->extractTechnicalDetails($response),
                    $response->getStatusCode()
                );
            }

            $data = $response->toArray();
            $results = $data['results'] ?? [];
            foreach ($results as $item) {
                $type = (string) ($item['type'] ?? '');
                if (strtolower($type) !== 'page') {
                    continue;
                }
                $list[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                ];
            }

            $url = $this->getNextPageUrl($response);
        } while ($url !== null);

        return $list;
    }

    /**
     * Get a folder by ID (for resolving spaceId when parent is a folder).
     *
     * @return array{id: string, title: string, spaceId: string}
     * @throws ApiException if folder not found
     */
    public function getFolder(string $folderId): array
    {
        $response = $this->client->request('GET', self::API_PREFIX . '/folders/' . $folderId);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Confluence folder \"{$folderId}\" not found.",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();

        return [
            'id' => (string) ($data['id'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'spaceId' => (string) ($data['spaceId'] ?? ''),
        ];
    }

    /**
     * Get direct child pages of a folder (same shape as getDirectChildPages for "title already exists" fallback).
     * Follows pagination via Link: next.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public function getDirectChildPagesOfFolder(string $folderId): array
    {
        $list = [];
        $url = self::API_PREFIX . '/folders/' . $folderId . '/direct-children?limit=50';

        do {
            $response = $this->client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                throw new ApiException(
                    'Failed to fetch folder children.',
                    $this->extractTechnicalDetails($response),
                    $response->getStatusCode()
                );
            }

            $data = $response->toArray();
            $results = $data['results'] ?? [];
            foreach ($results as $item) {
                $type = (string) ($item['type'] ?? '');
                if (strtolower($type) !== 'page') {
                    continue;
                }
                $list[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                ];
            }

            $url = $this->getNextPageUrl($response);
        } while ($url !== null);

        return $list;
    }

    /**
     * Resolve a space key (e.g. "DEV", "PROD") or numeric space ID to a numeric space ID.
     * If the input is all digits, it is treated as a space ID and returned as-is.
     * Otherwise tries filtering by key first (GET /spaces?keys=KEY), then falls back to listing all spaces with pagination.
     *
     * @throws ApiException if space not found
     */
    public function resolveSpaceId(string $spaceKey): string
    {
        $trimmed = trim($spaceKey);
        if ($trimmed === '') {
            throw new ApiException(
                "Confluence space \"{$spaceKey}\" not found.",
                'Space key: ' . $spaceKey,
                404
            );
        }
        if (ctype_digit($trimmed)) {
            return $trimmed;
        }

        $keyUpper = strtoupper($trimmed);
        $spaces = $this->getSpacesByKeys([$trimmed]);
        if ($spaces !== []) {
            return $spaces[0]['id'];
        }

        $spaces = $this->getSpaces();
        foreach ($spaces as $space) {
            if (strtoupper($space['key']) === $keyUpper) {
                return $space['id'];
            }
        }

        throw new ApiException(
            "Confluence space \"{$spaceKey}\" not found.",
            'Space key: ' . $spaceKey,
            404
        );
    }

    /**
     * List spaces filtered by keys (Confluence v2 supports keys query param).
     *
     * @param list<string> $keys Space keys (e.g. ["PROD", "DEV"])
     * @return array<int, array{id: string, key: string, name: string}>
     */
    public function getSpacesByKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $query = implode(',', array_map('trim', $keys));
        $url = self::API_PREFIX . '/spaces?limit=50&keys=' . rawurlencode($query);

        $response = $this->client->request('GET', $url);
        if ($response->getStatusCode() !== 200) {
            return [];
        }
        $data = $response->toArray();
        $results = $data['results'] ?? [];
        $list = [];
        foreach ($results as $item) {
            $list[] = [
                'id' => (string) ($item['id'] ?? ''),
                'key' => (string) ($item['key'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
            ];
        }

        return $list;
    }

    /**
     * Create a new page.
     *
     * @param string $spaceId Numeric space ID
     * @param string $title Page title
     * @param string $adfJsonString Stringified ADF document (atlas_doc_format)
     * @param string|null $parentId Optional parent page ID
     * @param string $status Page status: "current" (published) or "draft"
     * @return array{id: string, title: string, _links: array{webui: string}}
     */
    public function createPage(
        string $spaceId,
        string $title,
        string $adfJsonString,
        ?string $parentId = null,
        string $status = 'current'
    ): array {
        $body = [
            'spaceId' => $spaceId,
            'title' => $title,
            'status' => $status,
            'body' => [
                'representation' => 'atlas_doc_format',
                'value' => $adfJsonString,
            ],
        ];
        if ($parentId !== null && $parentId !== '') {
            $body['parentId'] = $parentId;
        }

        $response = $this->client->request('POST', self::API_PREFIX . '/pages', [
            'json' => $body,
        ]);

        $status = $response->getStatusCode();
        if ($status !== 201 && $status !== 200) {
            throw new ApiException(
                'Could not create Confluence page.',
                $this->extractTechnicalDetails($response),
                $status
            );
        }

        return $this->mapPageResponse($response);
    }

    /**
     * Get a page by ID (including current version number and spaceId).
     *
     * @return array{id: string, title: string, version: array{number: int}, spaceId: string|null, _links: array{webui: string}}
     */
    public function getPage(string $pageId): array
    {
        $response = $this->client->request('GET', self::API_PREFIX . '/pages/' . $pageId);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Confluence page \"{$pageId}\" not found.",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();
        $mapped = $this->mapPageResponseFromArray($data);
        $mapped['version'] = [
            'number' => (int) ($data['version']['number'] ?? 0),
        ];
        $mapped['spaceId'] = isset($data['spaceId']) ? (string) $data['spaceId'] : null;
        if ($mapped['spaceId'] === null && isset($data['space']['id'])) {
            $mapped['spaceId'] = (string) $data['space']['id'];
        }

        return $mapped;
    }

    /**
     * Update an existing page.
     *
     * @param string $pageId Page ID
     * @param string $title Page title
     * @param string $adfJsonString Stringified ADF document
     * @param int $versionNumber New version number (current + 1)
     * @param string $versionMessage Optional version message
     * @return array{id: string, title: string, _links: array{webui: string}}
     */
    public function updatePage(
        string $pageId,
        string $title,
        string $adfJsonString,
        int $versionNumber,
        string $versionMessage = 'Updated via stud-cli'
    ): array {
        $response = $this->client->request('PUT', self::API_PREFIX . '/pages/' . $pageId, [
            'json' => [
                'id' => $pageId,
                'title' => $title,
                'status' => 'current',
                'version' => [
                    'number' => $versionNumber,
                    'message' => $versionMessage,
                ],
                'body' => [
                    'representation' => 'atlas_doc_format',
                    'value' => $adfJsonString,
                ],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not update Confluence page \"{$pageId}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        return $this->mapPageResponse($response);
    }

    /**
     * Add labels to a page (Confluence v1 REST endpoint).
     *
     * @param string $pageId Page ID
     * @param list<string> $labelNames Label names (e.g. ["R&D", "DX"])
     * @throws ApiException if the request fails
     */
    public function addPageLabels(string $pageId, array $labelNames): void
    {
        if ($labelNames === []) {
            return;
        }
        $body = [];
        foreach ($labelNames as $name) {
            $body[] = ['prefix' => 'global', 'name' => trim($name)];
        }
        $response = $this->client->request(
            'POST',
            'rest/api/content/' . $pageId . '/label',
            ['json' => $body]
        );

        if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
            throw new ApiException(
                'Could not add labels to Confluence page.',
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string, title: string, _links: array{webui: string}}
     */
    protected function mapPageResponseFromArray(array $data): array
    {
        $links = $data['_links'] ?? [];
        $webui = is_string($links['webui'] ?? null) ? $links['webui'] : '';

        return [
            'id' => (string) ($data['id'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            '_links' => ['webui' => $webui],
        ];
    }

    /**
     * @return array{id: string, title: string, _links: array{webui: string}}
     */
    protected function mapPageResponse(ResponseInterface $response): array
    {
        return $this->mapPageResponseFromArray($response->toArray());
    }

    protected function extractTechnicalDetails(ResponseInterface $response): string
    {
        $statusCode = $response->getStatusCode();
        $responseBody = 'No response body';

        try {
            $content = $response->getContent(false);
            if ($content !== '') {
                $responseBody = mb_strlen($content) > 500
                    ? mb_substr($content, 0, 500) . '... (truncated)'
                    : $content;
            }
        } catch (\Exception $e) {
            $responseBody = 'Unable to read response body: ' . $e->getMessage();
        }

        return "HTTP {$statusCode}. {$responseBody}";
    }
}
