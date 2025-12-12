<?php

namespace App\Tests\View;

use App\DTO\WorkItem;
use App\Tests\CommandTestCase;
use App\View\Content;
use App\View\DefinitionItem;
use App\View\PageViewConfig;
use App\View\Section;
use Symfony\Component\Console\Style\SymfonyStyle;

class PageViewConfigTest extends CommandTestCase
{
    private PageViewConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $sections = [
            new Section(
                'Details',
                [
                    new DefinitionItem('item.show.label_key', fn ($dto) => $dto->key),
                    new DefinitionItem('item.show.label_title', fn ($dto) => $dto->title),
                ]
            ),
        ];

        $this->config = new PageViewConfig($sections, $this->translationService);
    }

    public function testGetTypeReturnsPage(): void
    {
        $this->assertSame('page', $this->config->getType());
    }

    public function testRenderRendersSectionsCorrectly(): void
    {
        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with('Details');
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->anything());

        $this->config->render([$issue], $io);
    }

    public function testRenderDoesNothingWhenEmptyDtos(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->never())
            ->method('section');

        $this->config->render([], $io);
    }

    public function testRenderDefinitionListCallsIoWithCorrectData(): void
    {
        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $this->translationService->expects($this->exactly(2))
            ->method('trans')
            ->willReturnCallback(fn ($key) => $key);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->anything());

        $this->config->render([$issue], $io);
    }

    public function testRenderContentWithListingFormatter(): void
    {
        $content = new Content(
            fn ($dto) => ['Item 1', 'Item 2'],
            'listing'
        );
        $section = new Section('Content Section', [$content]);
        $config = new PageViewConfig([$section], $this->translationService);

        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with('Content Section');
        $io->expects($this->once())
            ->method('listing')
            ->with(['Item 1', 'Item 2']);

        $config->render([$issue], $io);
    }

    public function testRenderContentWithTextFormatter(): void
    {
        $content = new Content(
            fn ($dto) => ['Line 1', 'Line 2'],
            'text'
        );
        $section = new Section('Content Section', [$content]);
        $config = new PageViewConfig([$section], $this->translationService);

        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with('Content Section');
        $io->expects($this->once())
            ->method('text')
            ->with(['Line 1', 'Line 2']);

        $config->render([$issue], $io);
    }

    public function testRenderContentWithStringContent(): void
    {
        $content = new Content(
            fn ($dto) => 'Simple text content',
            null
        );
        $section = new Section('Content Section', [$content]);
        $config = new PageViewConfig([$section], $this->translationService);

        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with('Content Section');
        $io->expects($this->once())
            ->method('text')
            ->with('Simple text content');

        $config->render([$issue], $io);
    }

    public function testRenderContentWithNonCallableExtractor(): void
    {
        $content = new Content(
            'not-a-callable',
            null
        );
        $section = new Section('Content Section', [$content]);
        $config = new PageViewConfig([$section], $this->translationService);

        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with('Content Section');
        $io->expects($this->never())
            ->method('text');
        $io->expects($this->never())
            ->method('listing');

        $config->render([$issue], $io);
    }

    public function testRenderSectionWithBothDefinitionItemsAndContent(): void
    {
        $section = new Section(
            'Mixed Section',
            [
                new DefinitionItem('item.show.label_key', fn ($dto) => $dto->key),
                new Content(fn ($dto) => 'Content text', null),
            ]
        );
        $config = new PageViewConfig([$section], $this->translationService);

        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with('Mixed Section');
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('text')
            ->with('Content text');

        $config->render([$issue], $io);
    }
}
