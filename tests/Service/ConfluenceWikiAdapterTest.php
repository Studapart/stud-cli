<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ConfluenceService;
use App\Service\ConfluenceWikiAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfluenceWikiAdapterTest extends TestCase
{
    private ConfluenceService&MockObject $confluenceService;

    private ConfluenceWikiAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confluenceService = $this->createMock(ConfluenceService::class);
        $this->adapter = new ConfluenceWikiAdapter($this->confluenceService);
    }

    public function testGetPageWithBodyDelegates(): void
    {
        $this->confluenceService->expects($this->once())
            ->method('getPageWithBody')
            ->with('42')
            ->willReturn(['id' => '42']);

        $this->assertSame(['id' => '42'], $this->adapter->getPageWithBody('42'));
    }

    public function testExtractPageIdFromUrlDelegates(): void
    {
        $this->confluenceService->expects($this->once())
            ->method('extractPageIdFromUrl')
            ->with('https://example/wiki/spaces/X/pages/42')
            ->willReturn('42');

        $this->assertSame('42', $this->adapter->extractPageIdFromUrl('https://example/wiki/spaces/X/pages/42'));
    }

    public function testGetPageDelegates(): void
    {
        $this->confluenceService->expects($this->once())
            ->method('getPage')
            ->with('42')
            ->willReturn(['id' => '42']);

        $this->assertSame(['id' => '42'], $this->adapter->getPage('42'));
    }

    public function testUpdatePageDelegates(): void
    {
        $this->confluenceService->expects($this->once())
            ->method('updatePage')
            ->with('42', 'Title', '{}', 2, 'msg')
            ->willReturn(['id' => '42']);

        $this->assertSame(['id' => '42'], $this->adapter->updatePage('42', 'Title', '{}', 2, 'msg'));
    }

    public function testGetFolderDelegates(): void
    {
        $this->confluenceService->expects($this->once())
            ->method('getFolder')
            ->with('folder-1')
            ->willReturn(['id' => 'folder-1']);

        $this->assertSame(['id' => 'folder-1'], $this->adapter->getFolder('folder-1'));
    }

    public function testResolveSpaceIdDelegates(): void
    {
        $this->confluenceService->expects($this->once())
            ->method('resolveSpaceId')
            ->with('SCI')
            ->willReturn('space-id');

        $this->assertSame('space-id', $this->adapter->resolveSpaceId('SCI'));
    }

    public function testCreatePageDelegates(): void
    {
        $this->confluenceService->expects($this->once())
            ->method('createPage')
            ->with('space-id', 'Title', '{}', 'parent', 'current')
            ->willReturn(['id' => '99']);

        $this->assertSame(['id' => '99'], $this->adapter->createPage('space-id', 'Title', '{}', 'parent', 'current'));
    }

    public function testGetDirectChildPagesDelegates(): void
    {
        $this->confluenceService->expects($this->once())
            ->method('getDirectChildPages')
            ->with('42')
            ->willReturn([['id' => '43']]);

        $this->assertSame([['id' => '43']], $this->adapter->getDirectChildPages('42'));
    }

    public function testGetDirectChildPagesOfFolderDelegates(): void
    {
        $this->confluenceService->expects($this->once())
            ->method('getDirectChildPagesOfFolder')
            ->with('folder-1')
            ->willReturn([['id' => '44']]);

        $this->assertSame([['id' => '44']], $this->adapter->getDirectChildPagesOfFolder('folder-1'));
    }
}
