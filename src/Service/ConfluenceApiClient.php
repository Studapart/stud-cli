<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ConfluencePageContent;
use App\DTO\ConfluencePageTarget;
use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ConfluenceApiClient
{
    /** Relative to Confluence base URL (base must end with / so path is appended under /wiki). */
    private const API_PREFIX = 'api/v2';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ConfluencePageMapper $pageMapper,
        private readonly ConfluenceResponseGuard $responseGuard,
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
        $results = $this->fetchAllResults(self::API_PREFIX . '/spaces?limit=50', 'Failed to fetch Confluence spaces.');

        return $this->mapSpaces($results);
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
        $url = self::API_PREFIX . '/pages/' . $parentPageId . '/direct-children?limit=50';

        return $this->mapChildPages($this->fetchAllResults($url, 'Failed to fetch child pages.'));
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
        $this->responseGuard->assertStatus($response, "Confluence folder \"{$folderId}\" not found.", 200);

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
        $url = self::API_PREFIX . '/folders/' . $folderId . '/direct-children?limit=50';

        return $this->mapChildPages($this->fetchAllResults($url, 'Failed to fetch folder children.'));
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

        return $this->mapSpaces($data['results'] ?? []);
    }

    /**
     * Create a new page.
     *
     * @param ConfluencePageTarget $target Space, optional parent page and status ("current" or "draft")
     * @param ConfluencePageContent $content Page title and stringified ADF document (atlas_doc_format)
     * @return array{id: string, title: string, _links: array{webui: string}}
     */
    public function createPage(ConfluencePageTarget $target, ConfluencePageContent $content): array
    {
        $body = [
            'spaceId' => $target->spaceId,
            'title' => $content->title,
            'status' => $target->status,
            'body' => [
                'representation' => 'atlas_doc_format',
                'value' => $content->adfJsonString,
            ],
        ];
        if ($target->parentId !== null && $target->parentId !== '') {
            $body['parentId'] = $target->parentId;
        }

        $response = $this->client->request('POST', self::API_PREFIX . '/pages', [
            'json' => $body,
        ]);
        $this->responseGuard->assertStatus($response, 'Could not create Confluence page.', 201, 200);

        return $this->pageMapper->mapPage($response->toArray());
    }

    /**
     * Get a page by ID (including current version number and spaceId).
     *
     * @return array{id: string, title: string, version: array{number: int}, spaceId: string|null, _links: array{webui: string}}
     */
    public function getPage(string $pageId): array
    {
        $response = $this->client->request('GET', self::API_PREFIX . '/pages/' . $pageId);
        $this->responseGuard->assertStatus($response, "Confluence page \"{$pageId}\" not found.", 200);

        return $this->pageMapper->mapPageWithVersion($response->toArray());
    }

    /**
     * Get a page by ID including body in ADF format.
     * Uses body-format=atlas_doc_format so the response includes the page body.
     *
     * @return array{id: string, title: string, version: array{number: int}, spaceId: string|null, _links: array{webui: string}, body: array{type: string, content?: array<int, mixed>}}
     */
    public function getPageWithBody(string $pageId): array
    {
        $url = self::API_PREFIX . '/pages/' . $pageId . '?body-format=atlas_doc_format';
        $response = $this->client->request('GET', $url);
        $this->responseGuard->assertStatus($response, "Confluence page \"{$pageId}\" not found.", 200);

        $data = $response->toArray();
        $mapped = $this->pageMapper->mapPageWithVersion($data);
        $mapped['body'] = $this->pageMapper->extractBodyAdf($data);

        return $mapped;
    }

    /**
     * Extract Confluence page ID from a Confluence page URL.
     * Supports: /wiki/spaces/SPACE/pages/123456 and /pages/123456 (path segment).
     *
     * @throws ApiException when the URL cannot be parsed to a page ID
     */
    public function extractPageIdFromUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new ApiException('Invalid Confluence URL: empty.', 'url: (empty)', 400);
        }
        $parsed = parse_url($trimmed);
        $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
        if ($path === '') {
            throw new ApiException('Invalid Confluence URL: no path.', 'url: ' . $trimmed, 400);
        }
        if (preg_match('#(?:^|/)pages/(\d+)(?:/|$)#', '/' . $path . '/', $m)) {
            return $m[1];
        }

        throw new ApiException(
            'Invalid Confluence URL: expected path like /wiki/spaces/SPACE/pages/123456 or /pages/123456.',
            'url: ' . $trimmed,
            400
        );
    }

    /**
     * Update an existing page.
     *
     * @param string $pageId Page ID
     * @param ConfluencePageContent $content Page title and stringified ADF document
     * @param int $versionNumber New version number (current + 1)
     * @param string $versionMessage Optional version message
     * @return array{id: string, title: string, _links: array{webui: string}}
     */
    public function updatePage(
        string $pageId,
        ConfluencePageContent $content,
        int $versionNumber,
        string $versionMessage = 'Updated via stud-cli'
    ): array {
        $response = $this->client->request('PUT', self::API_PREFIX . '/pages/' . $pageId, [
            'json' => [
                'id' => $pageId,
                'title' => $content->title,
                'status' => 'current',
                'version' => [
                    'number' => $versionNumber,
                    'message' => $versionMessage,
                ],
                'body' => [
                    'representation' => 'atlas_doc_format',
                    'value' => $content->adfJsonString,
                ],
            ],
        ]);
        $this->responseGuard->assertStatus($response, "Could not update Confluence page \"{$pageId}\".", 200);

        return $this->pageMapper->mapPage($response->toArray());
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
        $this->responseGuard->assertStatus($response, 'Could not add labels to Confluence page.', 200, 201);
    }

    /**
     * GET every page of a paginated v2 collection (Link: next) and return the combined `results`.
     *
     * @return list<array<string, mixed>>
     * @throws ApiException when any page request fails
     */
    private function fetchAllResults(string $url, string $errorMessage): array
    {
        $results = [];
        do {
            $response = $this->client->request('GET', $url);
            $this->responseGuard->assertStatus($response, $errorMessage, 200);

            $data = $response->toArray();
            foreach ($data['results'] ?? [] as $item) {
                $results[] = $item;
            }

            $url = $this->getNextPageUrl($response);
        } while ($url !== null);

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array{id: string, key: string, name: string}>
     */
    private function mapSpaces(array $results): array
    {
        return array_map(static fn (array $item): array => [
            'id' => (string) ($item['id'] ?? ''),
            'key' => (string) ($item['key'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
        ], array_values($results));
    }

    /**
     * Keep only `page` children (folders, whiteboards, etc. are skipped).
     *
     * @param list<array<string, mixed>> $results
     * @return array<int, array{id: string, title: string}>
     */
    private function mapChildPages(array $results): array
    {
        $pages = [];
        foreach ($results as $item) {
            if (strtolower((string) ($item['type'] ?? '')) !== 'page') {
                continue;
            }
            $pages[] = [
                'id' => (string) ($item['id'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
            ];
        }

        return $pages;
    }
}
