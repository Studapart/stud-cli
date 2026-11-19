<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ConfluencePageContent;
use App\DTO\ConfluencePageTarget;
use App\Exception\ApiException;

/**
 * Contract for wiki provider implementations (Confluence).
 *
 * Extends the read contract with the methods that change remote pages.
 */
interface WikiPort extends WikiReadPort
{
    /**
     * @return array{id: string, title: string, _links: array{webui: string}}
     * @throws ApiException
     */
    public function updatePage(
        string $pageId,
        ConfluencePageContent $content,
        int $versionNumber,
        string $versionMessage = 'Updated via stud-cli',
    ): array;

    /**
     * @return array{id: string, title: string, _links: array{webui: string}}
     * @throws ApiException
     */
    public function createPage(ConfluencePageTarget $target, ConfluencePageContent $content): array;
}
