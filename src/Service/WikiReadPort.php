<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;

/**
 * Wiki provider methods that read remote data.
 *
 * Show handlers depend on this interface so they cannot update or create a page.
 * {@see WikiPort} extends it and adds the methods that change remote data.
 */
interface WikiReadPort
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
     * @return array{id: string, title: string, spaceId: string}
     * @throws ApiException
     */
    public function getFolder(string $folderId): array;

    /** @throws ApiException */
    public function resolveSpaceId(string $spaceKey): string;

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
