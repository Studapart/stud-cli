<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\ConfluencePushInput;
use App\Exception\ApiException;
use App\Response\ConfluencePushResponse;
use App\Service\ConfluenceService;
use App\Service\MarkdownToAdfConverter;
use App\Service\TranslationService;

class ConfluencePushHandler
{
    public function __construct(
        private readonly ConfluenceService $confluenceService,
        private readonly MarkdownToAdfConverter $converter,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(ConfluencePushInput $input, string $baseUrl): ConfluencePushResponse
    {
        $content = trim($input->content);
        if ($content === '') {
            return ConfluencePushResponse::error(
                $this->translator->trans('confluence.push.error_no_content')
            );
        }

        if ($input->pageId !== null && $input->pageId !== '') {
            return $this->handleUpdate($input, $content, $baseUrl);
        }

        return $this->handleCreate($input, $content, $baseUrl);
    }

    protected function handleUpdate(ConfluencePushInput $input, string $content, string $baseUrl): ConfluencePushResponse
    {
        try {
            $page = $this->confluenceService->getPage($input->pageId);
        } catch (ApiException $e) {
            return ConfluencePushResponse::error(
                $this->translator->trans('confluence.push.error_page_not_found', ['%id%' => $input->pageId])
            );
        }

        $title = $input->title !== null && $input->title !== ''
            ? $input->title
            : $page['title'];
        $adfJson = $this->contentToAdfJson($content);
        if ($input->contactAccountId !== null && $input->contactDisplayName !== null) {
            $adfJson = $this->appendContactMentionToAdf($adfJson, $input->contactAccountId, $input->contactDisplayName);
        }
        $versionNumber = $page['version']['number'] + 1;

        try {
            $updated = $this->confluenceService->updatePage(
                $input->pageId,
                $title,
                $adfJson,
                $versionNumber
            );
        } catch (ApiException $e) {
            return ConfluencePushResponse::error($this->buildApiErrorMessage($e));
        }

        $url = $this->buildPageUrl($baseUrl, $updated['_links']['webui']);

        return ConfluencePushResponse::success(
            $updated['id'],
            $updated['title'],
            $url,
            'updated'
        );
    }

    protected function handleCreate(ConfluencePushInput $input, string $content, string $baseUrl): ConfluencePushResponse
    {
        $parentId = $input->parentId !== null && trim($input->parentId) !== '' ? trim($input->parentId) : null;

        $spaceId = null;
        if ($parentId !== null) {
            $parentResult = $this->resolveSpaceIdFromParent($parentId);
            if ($parentResult instanceof ConfluencePushResponse) {
                return $parentResult;
            }
            $spaceId = $parentResult['spaceId'];
        }

        if ($spaceId === null || $spaceId === '') {
            $resolved = $this->resolveSpaceIdWhenNoParent($input);
            if ($resolved instanceof ConfluencePushResponse) {
                return $resolved;
            }
            $spaceId = $resolved;
        }

        $titleError = $this->ensureTitleForCreate($input);
        if ($titleError !== null) {
            return $titleError;
        }

        $title = trim($input->title ?? '');
        $adfJson = $this->buildAdfJsonForCreate($input, $content);

        return $this->executeCreateWithDuplicateFallback(
            $spaceId,
            $parentId,
            $title,
            $adfJson,
            $input->status,
            $baseUrl
        );
    }

    /**
     * Resolve space ID from a parent page or folder. Returns error response on failure.
     *
     * @return ConfluencePushResponse|array{spaceId: ?string}
     */
    protected function resolveSpaceIdFromParent(string $parentId): ConfluencePushResponse|array
    {
        try {
            $parentPage = $this->confluenceService->getPage($parentId);

            return ['spaceId' => $parentPage['spaceId'] ?? null];
        } catch (ApiException $e) {
            if ($e->getStatusCode() !== 404) {
                return ConfluencePushResponse::error(
                    $this->translator->trans('confluence.push.error_page_not_found', ['%id%' => $parentId])
                );
            }

            try {
                $parentFolder = $this->confluenceService->getFolder($parentId);

                return ['spaceId' => $parentFolder['spaceId']];
            } catch (ApiException) {
                return ConfluencePushResponse::error(
                    $this->translator->trans('confluence.push.error_parent_not_found', ['%id%' => $parentId])
                );
            }
        }
    }

    /**
     * Resolve space ID from input space key when no parent was used.
     *
     * @return ConfluencePushResponse|string
     */
    protected function resolveSpaceIdWhenNoParent(ConfluencePushInput $input): ConfluencePushResponse|string
    {
        $space = $input->space;
        if ($space === null || trim($space) === '') {
            return ConfluencePushResponse::error(
                $this->translator->trans('confluence.push.error_space_required')
            );
        }

        try {
            return $this->confluenceService->resolveSpaceId(trim($space));
        } catch (ApiException) {
            return ConfluencePushResponse::error(
                $this->translator->trans('confluence.push.error_space_not_found', ['%key%' => $space])
            );
        }
    }

    /**
     * Validate title for create; returns error response if invalid.
     */
    protected function ensureTitleForCreate(ConfluencePushInput $input): ?ConfluencePushResponse
    {
        $title = $input->title;
        if ($title === null || trim($title) === '') {
            return ConfluencePushResponse::error(
                $this->translator->trans('confluence.push.error_no_title')
            );
        }

        return null;
    }

    /**
     * Build ADF JSON from content and optionally append contact mention.
     */
    protected function buildAdfJsonForCreate(ConfluencePushInput $input, string $content): string
    {
        $adfJson = $this->contentToAdfJson($content);
        if ($input->contactAccountId !== null && $input->contactDisplayName !== null) {
            $adfJson = $this->appendContactMentionToAdf(
                $adfJson,
                $input->contactAccountId,
                $input->contactDisplayName
            );
        }

        return $adfJson;
    }

    /**
     * Call createPage; on duplicate title under parent try update; otherwise return error.
     */
    protected function executeCreateWithDuplicateFallback(
        string $spaceId,
        ?string $parentId,
        string $title,
        string $adfJson,
        ?string $status,
        string $baseUrl
    ): ConfluencePushResponse {
        try {
            $created = $this->confluenceService->createPage(
                $spaceId,
                $title,
                $adfJson,
                $parentId,
                $status
            );
        } catch (ApiException $e) {
            if ($parentId !== null && $e->getStatusCode() === 400) {
                $details = $e->getTechnicalDetails();
                if (str_contains($details, 'title already exists') || str_contains($details, 'same TITLE')) {
                    $updated = $this->tryUpdateExistingPageUnderParent($parentId, $title, $adfJson, $baseUrl);
                    if ($updated !== null) {
                        return $updated;
                    }
                }
            }

            return ConfluencePushResponse::error($this->buildApiErrorMessage($e));
        }

        $url = $this->buildPageUrl($baseUrl, $created['_links']['webui']);

        return ConfluencePushResponse::success(
            $created['id'],
            $created['title'],
            $url,
            'created'
        );
    }

    protected function buildApiErrorMessage(ApiException $e): string
    {
        $msg = $this->translator->trans('confluence.push.error_api', ['%message%' => $e->getMessage()]);
        $details = $e->getTechnicalDetails();

        return $details !== '' ? $msg . ' ' . $details : $msg;
    }

    /**
     * When create failed with "title already exists", find the page under parent by title and update it.
     */
    protected function tryUpdateExistingPageUnderParent(
        string $parentId,
        string $title,
        string $adfJson,
        string $baseUrl
    ): ?ConfluencePushResponse {
        try {
            $children = $this->confluenceService->getDirectChildPages($parentId);
        } catch (ApiException) {
            try {
                $children = $this->confluenceService->getDirectChildPagesOfFolder($parentId);
            } catch (ApiException) {
                return null;
            }
        }
        foreach ($children as $child) {
            if ($child['title'] === $title) {
                try {
                    $page = $this->confluenceService->getPage($child['id']);
                } catch (ApiException) {
                    return null;
                }
                $versionNumber = $page['version']['number'] + 1;

                try {
                    $updated = $this->confluenceService->updatePage(
                        $child['id'],
                        $title,
                        $adfJson,
                        $versionNumber
                    );
                } catch (ApiException) {
                    return null;
                }
                $url = $this->buildPageUrl($baseUrl, $updated['_links']['webui']);

                return ConfluencePushResponse::success(
                    $updated['id'],
                    $updated['title'],
                    $url,
                    'updated'
                );
            }
        }

        return null;
    }

    protected function contentToAdfJson(string $markdown): string
    {
        $adf = $this->converter->convert($markdown);

        return json_encode($adf, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Append a "Contact: @DisplayName" paragraph (with ADF mention) to the end of the document.
     */
    protected function appendContactMentionToAdf(string $adfJson, string $accountId, string $displayName): string
    {
        $adf = json_decode($adfJson, true, 512, JSON_THROW_ON_ERROR);
        if (! isset($adf['content']) || ! is_array($adf['content'])) {
            $adf['content'] = [];
        }
        $mentionText = '@' . $displayName;
        $adf['content'][] = [
            'type' => 'rule',
        ];
        $adf['content'][] = [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Contact: '],
                [
                    'type' => 'mention',
                    'attrs' => [
                        'id' => $accountId,
                        'text' => $mentionText,
                    ],
                ],
            ],
        ];

        return json_encode($adf, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    protected function buildPageUrl(string $baseUrl, string $webuiPath): string
    {
        if ($webuiPath === '') {
            return rtrim($baseUrl, '/');
        }
        if (str_starts_with($webuiPath, 'http://') || str_starts_with($webuiPath, 'https://')) {
            return $webuiPath;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($webuiPath, '/');
    }
}
