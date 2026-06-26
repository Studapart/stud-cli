<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;

/**
 * Contract for wiki provider implementations (Confluence).
 */
interface WikiPort
{
    /**
     * @return array{id: string, title: string, version: array{number: int}, spaceId: string|null, _links: array{webui: string}, body: array{type: string, content?: array<int, mixed>}}
     * @throws ApiException
     */
    public function getPageWithBody(string $pageId): array;

    /** @throws ApiException */
    public function extractPageIdFromUrl(string $url): string;

    /**
     * @return array{id: string, title: string, version: array{number: int}, spaceId: string|null, _links: array{webui: string}}
     * @throws ApiException
     */
    public function getPage(string $pageId): array;

    /**
     * @return array{id: string, title: string, _links: array{webui: string}}
     * @throws ApiException
     */
    public function updatePage(
        string $pageId,
        string $title,
        string $adfJsonString,
        int $versionNumber,
        string $versionMessage = 'Updated via stud-cli',
    ): array;

    /**
     * @return array{id: string, title: string, spaceId: string}
     * @throws ApiException
     */
    public function getFolder(string $folderId): array;

    /** @throws ApiException */
    public function resolveSpaceId(string $spaceKey): string;

    /**
     * @return array{id: string, title: string, _links: array{webui: string}}
     * @throws ApiException
     */
    public function createPage(
        string $spaceId,
        string $title,
        string $adfJsonString,
        ?string $parentId = null,
        string $status = 'current',
    ): array;

    /**
     * @return array<int, array{id: string, title: string}>
     * @throws ApiException
     */
    public function getDirectChildPages(string $parentPageId): array;

    /**
     * @return array<int, array{id: string, title: string}>
     * @throws ApiException
     */
    public function getDirectChildPagesOfFolder(string $folderId): array;
}
