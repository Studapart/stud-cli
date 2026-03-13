<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\ConfluencePushInput;
use App\Exception\ApiException;
use App\Handler\ConfluencePushHandler;
use App\Service\ConfluenceService;
use App\Service\MarkdownToAdfConverter;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfluencePushHandlerTest extends TestCase
{
    private ConfluenceService&MockObject $confluenceService;
    private MarkdownToAdfConverter&MockObject $converter;
    private TranslationService&MockObject $translator;

    protected function setUp(): void
    {
        $this->confluenceService = $this->createMock(ConfluenceService::class);
        $this->converter = $this->createMock(MarkdownToAdfConverter::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => $id . ':' . json_encode($params)
        );
    }

    private function createHandler(): ConfluencePushHandler
    {
        return new ConfluencePushHandler(
            $this->confluenceService,
            $this->converter,
            $this->translator
        );
    }

    public function testHandleCreateFlowSuccess(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('resolveSpaceId')
            ->with('DEV')
            ->willReturn('1');
        $this->converter->expects(self::once())
            ->method('convert')
            ->with('# Hello')
            ->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('createPage')
            ->with(
                '1',
                'My Page',
                '{"type":"doc","version":1,"content":[]}',
                null,
                'current'
            )
            ->willReturn([
                'id' => '12345',
                'title' => 'My Page',
                '_links' => ['webui' => '/spaces/DEV/pages/12345'],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('# Hello', space: 'DEV', title: 'My Page');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame('12345', $response->pageId);
        self::assertSame('My Page', $response->title);
        self::assertSame('created', $response->action);
        self::assertStringContainsString('12345', $response->url ?? '');
    }

    public function testHandleUpdateFlowSuccess(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('getPage')
            ->with('12345')
            ->willReturn([
                'id' => '12345',
                'title' => 'Old Title',
                'version' => ['number' => 2],
                '_links' => ['webui' => '/spaces/DEV/pages/12345'],
            ]);
        $this->converter->expects(self::once())
            ->method('convert')
            ->with('# Updated')
            ->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('updatePage')
            ->with(
                '12345',
                'New Title',
                '{"type":"doc","version":1,"content":[]}',
                3
            )
            ->willReturn([
                'id' => '12345',
                'title' => 'New Title',
                '_links' => ['webui' => '/spaces/DEV/pages/12345'],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('# Updated', title: 'New Title', pageId: '12345');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame('12345', $response->pageId);
        self::assertSame('New Title', $response->title);
        self::assertSame('updated', $response->action);
    }

    public function testHandleUpdateKeepsExistingTitleWhenNotProvided(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('getPage')
            ->with('12345')
            ->willReturn([
                'id' => '12345',
                'title' => 'Existing Title',
                'version' => ['number' => 1],
                '_links' => ['webui' => '/wiki/spaces/DEV/pages/12345'],
            ]);
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('updatePage')
            ->with('12345', 'Existing Title', self::anything(), 2)
            ->willReturn([
                'id' => '12345',
                'title' => 'Existing Title',
                '_links' => ['webui' => '/wiki/spaces/DEV/pages/12345'],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', pageId: '12345');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame('Existing Title', $response->title);
    }

    public function testHandleReturnsErrorWhenContentEmpty(): void
    {
        $this->confluenceService->expects(self::never())->method('createPage');
        $this->confluenceService->expects(self::never())->method('getPage');

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('   ', space: 'DEV', title: 'Title');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.push.error_no_content', $response->getError() ?? '');
    }

    public function testHandleCreateReturnsErrorWhenSpaceMissing(): void
    {
        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', title: 'Title');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.push.error_space_required', $response->getError() ?? '');
    }

    public function testHandleCreateReturnsErrorWhenTitleMissing(): void
    {
        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', space: 'DEV');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.push.error_no_title', $response->getError() ?? '');
    }

    public function testHandleCreateReturnsErrorWhenSpaceNotFound(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('resolveSpaceId')
            ->with('MISSING')
            ->willThrowException(new ApiException('Space not found', 'detail', 404));
        $this->converter->expects(self::never())->method('convert');

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', space: 'MISSING', title: 'Title');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.push.error_space_not_found', $response->getError() ?? '');
    }

    public function testHandleUpdateReturnsErrorWhenPageNotFound(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('getPage')
            ->with('99999')
            ->willThrowException(new ApiException('Page not found', 'detail', 404));

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', pageId: '99999');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.push.error_page_not_found', $response->getError() ?? '');
    }

    public function testHandleCreateReturnsErrorOnApiException(): void
    {
        $this->confluenceService->expects(self::once())->method('resolveSpaceId')->with('DEV')->willReturn('1');
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('createPage')
            ->willThrowException(new ApiException('Server error', 'detail', 500));

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', space: 'DEV', title: 'Title');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.push.error_api', $response->getError() ?? '');
    }

    public function testHandleUpdateReturnsErrorWhenUpdatePageThrows(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('getPage')
            ->with('12345')
            ->willReturn([
                'id' => '12345',
                'title' => 'Old',
                'version' => ['number' => 1],
                '_links' => ['webui' => '/spaces/DEV/pages/12345'],
            ]);
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('updatePage')
            ->willThrowException(new ApiException('Conflict', 'detail', 409));

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', pageId: '12345');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.push.error_api', $response->getError() ?? '');
    }

    public function testBuildPageUrlWithEmptyWebuiReturnsBaseUrl(): void
    {
        $this->confluenceService->expects(self::once())->method('resolveSpaceId')->with('DEV')->willReturn('1');
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('createPage')
            ->willReturn([
                'id' => '99',
                'title' => 'Page',
                '_links' => ['webui' => ''],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('x', space: 'DEV', title: 'Page');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame('https://example.atlassian.net/wiki', $response->url);
    }

    public function testBuildPageUrlWithAbsoluteUrlReturnsAsIs(): void
    {
        $this->confluenceService->expects(self::once())->method('resolveSpaceId')->with('DEV')->willReturn('1');
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $fullUrl = 'https://other.atlassian.net/wiki/spaces/DEV/pages/999';
        $this->confluenceService->expects(self::once())
            ->method('createPage')
            ->willReturn([
                'id' => '999',
                'title' => 'Page',
                '_links' => ['webui' => $fullUrl],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('x', space: 'DEV', title: 'Page');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame($fullUrl, $response->url);
    }

    public function testCreateWithParentAsFolderUsesFolderSpaceId(): void
    {
        $parentId = '5315756039';
        $this->confluenceService->expects(self::once())
            ->method('getPage')
            ->with($parentId)
            ->willThrowException(new ApiException('Not found', 'detail', 404));
        $this->confluenceService->expects(self::once())
            ->method('getFolder')
            ->with($parentId)
            ->willReturn(['id' => $parentId, 'title' => 'Research', 'spaceId' => '42']);
        $this->confluenceService->expects(self::never())->method('resolveSpaceId');
        $this->converter->expects(self::once())->method('convert')->with('# Doc')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('createPage')
            ->with('42', 'My Doc', self::anything(), $parentId, 'current')
            ->willReturn([
                'id' => '999',
                'title' => 'My Doc',
                '_links' => ['webui' => '/spaces/PROD/pages/999'],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('# Doc', parentId: $parentId, title: 'My Doc');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame('999', $response->pageId);
    }

    public function testCreateWithParentNotFoundReturnsError(): void
    {
        $parentId = '99999';
        $this->confluenceService->expects(self::once())
            ->method('getPage')
            ->with($parentId)
            ->willThrowException(new ApiException('Not found', 'detail', 404));
        $this->confluenceService->expects(self::once())
            ->method('getFolder')
            ->with($parentId)
            ->willThrowException(new ApiException('Folder not found', 'detail', 404));
        $this->converter->expects(self::never())->method('convert');

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', parentId: $parentId, title: 'Title');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.push.error_parent_not_found', $response->getError() ?? '');
    }

    public function testCreateWithContactAppendsMentionToAdf(): void
    {
        $accountId = 'abc-123';
        $displayName = 'Jane Doe';
        $this->confluenceService->expects(self::once())->method('resolveSpaceId')->with('DEV')->willReturn('1');
        $this->converter->expects(self::once())->method('convert')->with('body')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('createPage')
            ->with(
                '1',
                'Title',
                self::callback(function (string $adfJson) use ($accountId, $displayName): bool {
                    $adf = json_decode($adfJson, true);

                    return is_array($adf)
                        && isset($adf['content'])
                        && str_contains($adfJson, $accountId)
                        && str_contains($adfJson, 'Contact:')
                        && str_contains($adfJson, $displayName);
                }),
                null,
                'current'
            )
            ->willReturn([
                'id' => '1',
                'title' => 'Title',
                '_links' => ['webui' => '/pages/1'],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput(
            'body',
            space: 'DEV',
            title: 'Title',
            contactAccountId: $accountId,
            contactDisplayName: $displayName
        );
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
    }

    public function testCreateDuplicateTitleUnderParentUpdatesExistingPage(): void
    {
        $parentId = 'parent1';
        $title = 'Existing Title';
        $parentPage = ['id' => $parentId, 'title' => 'Parent', 'spaceId' => '1'];
        $childPage = [
            'id' => 'child1',
            'title' => $title,
            'version' => ['number' => 2],
            '_links' => ['webui' => '/spaces/DEV/pages/child1'],
        ];
        $this->confluenceService->expects(self::exactly(2))
            ->method('getPage')
            ->willReturnCallback(function (string $id) use ($parentId, $parentPage, $childPage) {
                if ($id === $parentId) {
                    return $parentPage;
                }
                if ($id === 'child1') {
                    return $childPage;
                }

                throw new \InvalidArgumentException('Unexpected page id: ' . $id);
            });
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('createPage')
            ->with('1', $title, self::anything(), $parentId, 'current')
            ->willThrowException(
                new ApiException('Bad request', 'title already exists', 400)
            );
        $this->confluenceService->expects(self::once())
            ->method('getDirectChildPages')
            ->with($parentId)
            ->willReturn([['id' => 'child1', 'title' => $title]]);
        $this->confluenceService->expects(self::once())
            ->method('updatePage')
            ->with('child1', $title, self::anything(), 3)
            ->willReturn([
                'id' => 'child1',
                'title' => $title,
                '_links' => ['webui' => '/spaces/DEV/pages/child1'],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', parentId: $parentId, title: $title);
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame('child1', $response->pageId);
        self::assertSame('updated', $response->action);
    }

    public function testHandleUpdateWithContactAppendsMentionToAdf(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('getPage')
            ->with('12345')
            ->willReturn([
                'id' => '12345',
                'title' => 'Old',
                'version' => ['number' => 1],
                '_links' => ['webui' => '/spaces/DEV/pages/12345'],
            ]);
        $this->converter->expects(self::once())->method('convert')->with('body')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())
            ->method('updatePage')
            ->with(
                '12345',
                'New Title',
                self::callback(function (string $adfJson): bool {
                    return str_contains($adfJson, 'Contact:') && str_contains($adfJson, 'Jane Doe');
                }),
                2
            )
            ->willReturn([
                'id' => '12345',
                'title' => 'New Title',
                '_links' => ['webui' => '/spaces/DEV/pages/12345'],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput(
            'body',
            title: 'New Title',
            pageId: '12345',
            contactAccountId: 'acc-1',
            contactDisplayName: 'Jane Doe'
        );
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
    }

    public function testCreateWithParentPageReturnsErrorWhenGetPageFailsWithNon404(): void
    {
        $parentId = '99999';
        $this->confluenceService->expects(self::once())
            ->method('getPage')
            ->with($parentId)
            ->willThrowException(new ApiException('Server error', 'detail', 500));
        $this->confluenceService->expects(self::never())->method('getFolder');
        $this->converter->expects(self::never())->method('convert');

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', parentId: $parentId, title: 'Title');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.push.error_page_not_found', $response->getError() ?? '');
    }

    public function testCreateDuplicateTitleUnderParentUsesFolderChildrenWhenPageChildrenThrow(): void
    {
        $parentId = 'folder1';
        $title = 'Same Title';
        $this->confluenceService->expects(self::exactly(2))
            ->method('getPage')
            ->willReturnCallback(function (string $id) use ($parentId, $title) {
                if ($id === $parentId) {
                    return ['id' => $parentId, 'title' => 'Parent', 'spaceId' => '1'];
                }
                if ($id === 'child1') {
                    return [
                        'id' => 'child1',
                        'title' => $title,
                        'version' => ['number' => 2],
                        '_links' => ['webui' => '/p/child1'],
                    ];
                }

                throw new \InvalidArgumentException('Unexpected');
            });
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())->method('createPage')
            ->willThrowException(new ApiException('Bad request', 'title already exists', 400));
        $this->confluenceService->expects(self::once())
            ->method('getDirectChildPages')
            ->with($parentId)
            ->willThrowException(new ApiException('Failed', 'detail', 500));
        $this->confluenceService->expects(self::once())
            ->method('getDirectChildPagesOfFolder')
            ->with($parentId)
            ->willReturn([['id' => 'child1', 'title' => $title]]);
        $this->confluenceService->expects(self::once())
            ->method('updatePage')
            ->with('child1', $title, self::anything(), 3)
            ->willReturn([
                'id' => 'child1',
                'title' => $title,
                '_links' => ['webui' => '/p/child1'],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', parentId: $parentId, title: $title);
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame('child1', $response->pageId);
    }

    public function testCreateDuplicateTitleUnderParentReturnsErrorWhenBothChildCallsThrow(): void
    {
        $parentId = 'p1';
        $title = 'Same';
        $this->confluenceService->expects(self::once())->method('getPage')->with($parentId)
            ->willReturn(['id' => $parentId, 'title' => 'Parent', 'spaceId' => '1']);
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())->method('createPage')
            ->willThrowException(new ApiException('Bad request', 'title already exists', 400));
        $this->confluenceService->expects(self::once())->method('getDirectChildPages')->with($parentId)
            ->willThrowException(new ApiException('Failed', 'detail', 500));
        $this->confluenceService->expects(self::once())->method('getDirectChildPagesOfFolder')->with($parentId)
            ->willThrowException(new ApiException('Folder failed', 'detail', 500));

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', parentId: $parentId, title: $title);
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertNotEmpty($response->getError());
    }

    public function testCreateDuplicateTitleUnderParentReturnsErrorWhenNoMatchingChild(): void
    {
        $parentId = 'parent1';
        $title = 'Unique Title';
        $this->confluenceService->expects(self::once())
            ->method('getPage')
            ->with($parentId)
            ->willReturn(['id' => $parentId, 'title' => 'Parent', 'spaceId' => '1']);
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())->method('createPage')
            ->willThrowException(new ApiException('Bad request', 'title already exists', 400));
        $this->confluenceService->expects(self::once())->method('getDirectChildPages')->with($parentId)
            ->willReturn([['id' => 'other', 'title' => 'Other Title']]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', parentId: $parentId, title: $title);
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertNotEmpty($response->getError());
    }

    public function testCreateDuplicateTitleUnderParentReturnsErrorWhenGetPageOfChildThrows(): void
    {
        $parentId = 'p1';
        $title = 'Same';
        $this->confluenceService->expects(self::exactly(2))
            ->method('getPage')
            ->willReturnCallback(function (string $id) use ($parentId) {
                if ($id === $parentId) {
                    return ['id' => $parentId, 'title' => 'Parent', 'spaceId' => '1'];
                }
                if ($id === 'child1') {
                    throw new ApiException('Not found', 'detail', 500);
                }

                throw new \InvalidArgumentException('Unexpected');
            });
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())->method('createPage')
            ->willThrowException(new ApiException('Bad request', 'title already exists', 400));
        $this->confluenceService->expects(self::once())->method('getDirectChildPages')->with($parentId)
            ->willReturn([['id' => 'child1', 'title' => $title]]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', parentId: $parentId, title: $title);
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertNotEmpty($response->getError());
    }

    public function testCreateDuplicateTitleUnderParentReturnsErrorWhenUpdatePageThrows(): void
    {
        $parentId = 'p1';
        $title = 'Same';
        $this->confluenceService->expects(self::exactly(2))
            ->method('getPage')
            ->willReturnCallback(function (string $id) use ($parentId, $title) {
                if ($id === $parentId) {
                    return ['id' => $parentId, 'title' => 'Parent', 'spaceId' => '1'];
                }
                if ($id === 'child1') {
                    return [
                        'id' => 'child1',
                        'title' => $title,
                        'version' => ['number' => 1],
                        '_links' => ['webui' => '/p/child1'],
                    ];
                }

                throw new \InvalidArgumentException('Unexpected');
            });
        $this->converter->expects(self::once())->method('convert')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->confluenceService->expects(self::once())->method('createPage')
            ->willThrowException(new ApiException('Bad request', 'title already exists', 400));
        $this->confluenceService->expects(self::once())->method('getDirectChildPages')->with($parentId)
            ->willReturn([['id' => 'child1', 'title' => $title]]);
        $this->confluenceService->expects(self::once())->method('updatePage')
            ->willThrowException(new ApiException('Conflict', 'detail', 409));

        $handler = $this->createHandler();
        $input = new ConfluencePushInput('content', parentId: $parentId, title: $title);
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertNotEmpty($response->getError());
    }

    public function testCreateWithContactAppendsMentionWhenAdfHasNoContentKey(): void
    {
        $this->confluenceService->expects(self::once())->method('resolveSpaceId')->with('DEV')->willReturn('1');
        $this->converter->expects(self::once())->method('convert')->with('x')->willReturn(['type' => 'doc', 'version' => 1]);
        $this->confluenceService->expects(self::once())
            ->method('createPage')
            ->with(
                '1',
                'Title',
                self::callback(function (string $adfJson): bool {
                    $adf = json_decode($adfJson, true);

                    return isset($adf['content']) && is_array($adf['content']) && count($adf['content']) >= 2;
                }),
                null,
                'current'
            )
            ->willReturn([
                'id' => '1',
                'title' => 'Title',
                '_links' => ['webui' => '/pages/1'],
            ]);

        $handler = $this->createHandler();
        $input = new ConfluencePushInput(
            'x',
            space: 'DEV',
            title: 'Title',
            contactAccountId: 'acc',
            contactDisplayName: 'User'
        );
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
    }
}
