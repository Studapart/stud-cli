<?php

declare(strict_types=1);

namespace App\Service;

final class ConfluenceWikiAdapter implements WikiPort
{
    public function __construct(
        private readonly ConfluenceApiClient $confluenceApiClient,
    ) {
    }

    public function getPageWithBody(string $pageId): array
    {
        return $this->confluenceApiClient->getPageWithBody($pageId);
    }

    public function extractPageIdFromUrl(string $url): string
    {
        return $this->confluenceApiClient->extractPageIdFromUrl($url);
    }

    public function getPage(string $pageId): array
    {
        return $this->confluenceApiClient->getPage($pageId);
    }

    public function updatePage(
        string $pageId,
        string $title,
        string $adfJsonString,
        int $versionNumber,
        string $versionMessage = 'Updated via stud-cli',
    ): array {
        return $this->confluenceApiClient->updatePage($pageId, $title, $adfJsonString, $versionNumber, $versionMessage);
    }

    public function getFolder(string $folderId): array
    {
        return $this->confluenceApiClient->getFolder($folderId);
    }

    public function resolveSpaceId(string $spaceKey): string
    {
        return $this->confluenceApiClient->resolveSpaceId($spaceKey);
    }

    public function createPage(
        string $spaceId,
        string $title,
        string $adfJsonString,
        ?string $parentId = null,
        string $status = 'current',
    ): array {
        return $this->confluenceApiClient->createPage($spaceId, $title, $adfJsonString, $parentId, $status);
    }

    public function getDirectChildPages(string $parentPageId): array
    {
        return $this->confluenceApiClient->getDirectChildPages($parentPageId);
    }

    public function getDirectChildPagesOfFolder(string $folderId): array
    {
        return $this->confluenceApiClient->getDirectChildPagesOfFolder($folderId);
    }
}
