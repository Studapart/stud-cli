<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\ConfluenceShowInput;
use App\Exception\ApiException;
use App\Response\ConfluenceShowResponse;
use App\Service\AdfToMarkdownConverter;
use App\Service\ConfluenceService;
use App\Service\TranslationService;

class ConfluenceShowHandler
{
    public function __construct(
        private readonly ConfluenceService $confluenceService,
        private readonly AdfToMarkdownConverter $adfConverter,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(ConfluenceShowInput $input, string $baseUrl): ConfluenceShowResponse
    {
        try {
            $pageId = $this->resolvePageId($input);
        } catch (ApiException $e) {
            return ConfluenceShowResponse::error($e->getMessage());
        }
        if ($pageId === null) {
            return ConfluenceShowResponse::error(
                $this->translator->trans('confluence.show.error_page_or_url_required')
            );
        }

        try {
            $page = $this->confluenceService->getPageWithBody($pageId);
        } catch (ApiException $e) {
            return ConfluenceShowResponse::error(
                $this->translator->trans('confluence.show.error_page_not_found', ['%id%' => $pageId])
            );
        }

        $bodyAdf = $page['body'];
        $bodyMarkdown = $this->adfConverter->convert($bodyAdf);
        $url = $this->buildPageUrl($baseUrl, $page['_links']['webui']);

        return ConfluenceShowResponse::success(
            $page['id'],
            $page['title'],
            $url,
            $bodyMarkdown
        );
    }

    /**
     * Resolve page ID from input. Prefer pageId when both are set.
     * Returns null only when neither pageId nor url is provided.
     *
     */
    protected function resolvePageId(ConfluenceShowInput $input): ?string
    {
        $pageId = $input->pageId !== null && trim($input->pageId) !== '' ? trim($input->pageId) : null;
        if ($pageId !== null) {
            return $pageId;
        }
        $url = $input->url !== null && trim($input->url) !== '' ? trim($input->url) : null;
        if ($url === null) {
            return null;
        }

        return $this->confluenceService->extractPageIdFromUrl($url);
    }

    protected function buildPageUrl(string $baseUrl, string $webuiPath): string
    {
        $base = rtrim($baseUrl, '/');
        $path = ltrim($webuiPath, '/');

        return $path !== '' ? $base . '/' . $path : $base;
    }
}
