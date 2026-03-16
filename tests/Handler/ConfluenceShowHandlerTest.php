<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\ConfluenceShowInput;
use App\Exception\ApiException;
use App\Handler\ConfluenceShowHandler;
use App\Service\AdfToMarkdownConverter;
use App\Service\ConfluenceService;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfluenceShowHandlerTest extends TestCase
{
    private ConfluenceService&MockObject $confluenceService;
    private AdfToMarkdownConverter&MockObject $adfConverter;
    private TranslationService&MockObject $translator;

    protected function setUp(): void
    {
        $this->confluenceService = $this->createMock(ConfluenceService::class);
        $this->adfConverter = $this->createMock(AdfToMarkdownConverter::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => $id . ':' . json_encode($params)
        );
    }

    private function createHandler(): ConfluenceShowHandler
    {
        return new ConfluenceShowHandler(
            $this->confluenceService,
            $this->adfConverter,
            $this->translator
        );
    }

    public function testHandleSuccessWithPageId(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('getPageWithBody')
            ->with('12345')
            ->willReturn([
                'id' => '12345',
                'title' => 'My Page',
                '_links' => ['webui' => '/spaces/DEV/pages/12345'],
                'body' => ['type' => 'doc', 'content' => []],
            ]);
        $this->adfConverter->expects(self::once())
            ->method('convert')
            ->with(['type' => 'doc', 'content' => []])
            ->willReturn('Converted body');

        $handler = $this->createHandler();
        $input = new ConfluenceShowInput(pageId: '12345');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame('12345', $response->id);
        self::assertSame('My Page', $response->title);
        self::assertSame('https://example.atlassian.net/wiki/spaces/DEV/pages/12345', $response->url);
        self::assertSame('Converted body', $response->body);
    }

    public function testHandleSuccessWithUrlResolvesPageId(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('extractPageIdFromUrl')
            ->with('https://example.atlassian.net/wiki/spaces/DEV/pages/123456')
            ->willReturn('123456');
        $this->confluenceService->expects(self::once())
            ->method('getPageWithBody')
            ->with('123456')
            ->willReturn([
                'id' => '123456',
                'title' => 'From URL',
                '_links' => ['webui' => '/spaces/DEV/pages/123456'],
                'body' => ['type' => 'doc', 'content' => []],
            ]);
        $this->adfConverter->expects(self::once())->method('convert')->willReturn('');

        $handler = $this->createHandler();
        $input = new ConfluenceShowInput(url: 'https://example.atlassian.net/wiki/spaces/DEV/pages/123456');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertTrue($response->isSuccess());
        self::assertSame('123456', $response->id);
    }

    public function testHandlePrefersPageIdWhenBothProvided(): void
    {
        $this->confluenceService->expects(self::never())->method('extractPageIdFromUrl');
        $this->confluenceService->expects(self::once())
            ->method('getPageWithBody')
            ->with('999')
            ->willReturn([
                'id' => '999',
                'title' => 'By ID',
                '_links' => ['webui' => '/pages/999'],
                'body' => ['type' => 'doc', 'content' => []],
            ]);
        $this->adfConverter->expects(self::once())->method('convert')->willReturn('');

        $handler = $this->createHandler();
        $input = new ConfluenceShowInput(pageId: '999', url: 'https://example.com/wiki/pages/123456');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertSame('999', $response->id);
    }

    public function testHandleErrorWhenNeitherPageIdNorUrl(): void
    {
        $handler = $this->createHandler();
        $input = new ConfluenceShowInput();
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('confluence.show.error_page_or_url_required', $response->getError() ?? '');
    }

    public function testHandleErrorWhenPageNotFound(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('getPageWithBody')
            ->with('99999')
            ->willThrowException(new ApiException('Not found', '', 404));

        $handler = $this->createHandler();
        $input = new ConfluenceShowInput(pageId: '99999');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('99999', $response->getError() ?? '');
    }

    public function testHandleErrorWhenUrlInvalid(): void
    {
        $this->confluenceService->expects(self::once())
            ->method('extractPageIdFromUrl')
            ->with('not-a-url')
            ->willThrowException(new ApiException('Invalid Confluence URL: no path.', 'url: not-a-url', 400));

        $handler = $this->createHandler();
        $input = new ConfluenceShowInput(url: 'not-a-url');
        $response = $handler->handle($input, 'https://example.atlassian.net/wiki');

        self::assertFalse($response->isSuccess());
        self::assertSame('Invalid Confluence URL: no path.', $response->getError());
    }
}
