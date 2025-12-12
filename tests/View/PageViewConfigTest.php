<?php

namespace App\Tests\View;

use App\DTO\WorkItem;
use App\Service\ColorHelper;
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

        $this->config = new PageViewConfig($sections, $this->translationService, null);
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
        $config = new PageViewConfig([$section], $this->translationService, null);

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
        $config = new PageViewConfig([$section], $this->translationService, null);

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
        $config = new PageViewConfig([$section], $this->translationService, null);

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
        $config = new PageViewConfig([$section], $this->translationService, null);

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
        $config = new PageViewConfig([$section], $this->translationService, null);

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

    public function testRenderWithColorHelperRegistersStyles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $sections = [
            new Section(
                'Details',
                [
                    new DefinitionItem('item.show.label_key', fn ($dto) => $dto->key),
                ]
            ),
        ];
        $config = new PageViewConfig($sections, $this->translationService, $colorHelper);
        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $this->translationService->expects($this->once())
            ->method('trans')
            ->willReturn('Key');

        $io = $this->createMock(SymfonyStyle::class);
        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->willReturnCallback(fn ($color, $text) => "<{$color}>{$text}</>");
        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('Details'));
        $io->expects($this->once())
            ->method('definitionList');

        $config->render([$issue], $io);
    }

    public function testRenderSectionWithColorHelperAppliesColorsToSectionTitle(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $section = new Section('Test Section', []);
        $config = new PageViewConfig([$section], $this->translationService, $colorHelper);
        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->once())
            ->method('format')
            ->with('section_title', 'Test Section')
            ->willReturn('<section_title>Test Section</>');
        $io->expects($this->once())
            ->method('section')
            ->with('<section_title>Test Section</>');

        $config->render([$issue], $io);
    }

    public function testRenderDefinitionListWithColorHelperAppliesColors(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $section = new Section('Details', [
            new DefinitionItem('item.show.label_key', fn ($dto) => $dto->key),
        ]);
        $config = new PageViewConfig([$section], $this->translationService, $colorHelper);
        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $this->translationService->expects($this->once())
            ->method('trans')
            ->with('item.show.label_key')
            ->willReturn('Key');

        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeast(2))
            ->method('format')
            ->willReturnCallback(function ($colorName, $text) {
                return "<{$colorName}>{$text}</>";
            });

        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('Details'));
        $io->expects($this->once())
            ->method('definitionList')
            ->with($this->anything());

        $config->render([$issue], $io);
    }

    public function testRenderContentWithColorHelperAppliesColorsToListing(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $content = new Content(
            fn ($dto) => ['Item 1', 'Item 2'],
            'listing'
        );
        $section = new Section('Content Section', [$content]);
        $config = new PageViewConfig([$section], $this->translationService, $colorHelper);
        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeast(2))
            ->method('format')
            ->willReturnCallback(function ($colorName, $text) {
                if ($colorName === 'section_title') {
                    return "<{$colorName}>{$text}</>";
                }

                return "<{$colorName}>{$text}</>";
            });

        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('Content Section'));
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 2;
            }));

        $config->render([$issue], $io);
    }

    public function testRenderContentWithColorHelperAppliesColorsToText(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $content = new Content(
            fn ($dto) => 'Simple text',
            null
        );
        $section = new Section('Content Section', [$content]);
        $config = new PageViewConfig([$section], $this->translationService, $colorHelper);
        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeast(1))
            ->method('format')
            ->willReturnCallback(function ($colorName, $text) {
                return "<{$colorName}>{$text}</>";
            });

        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('Content Section'));
        $io->expects($this->once())
            ->method('text')
            ->with($this->stringContains('Simple text'));

        $config->render([$issue], $io);
    }

    public function testRenderContentWithColorHelperAppliesColorsToTextArray(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $content = new Content(
            fn ($dto) => ['Line 1', 'Line 2'],
            'text'
        );
        $section = new Section('Content Section', [$content]);
        $config = new PageViewConfig([$section], $this->translationService, $colorHelper);
        $issue = new WorkItem('1', 'TPW-1', 'Title', 'To Do', 'User', 'desc', [], 'Task');

        $io = $this->createMock(SymfonyStyle::class);
        $colorHelper->expects($this->once())
            ->method('registerStyles')
            ->with($io);
        $colorHelper->expects($this->atLeast(2))
            ->method('format')
            ->willReturnCallback(function ($colorName, $text) {
                return "<{$colorName}>{$text}</>";
            });

        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('Content Section'));
        $io->expects($this->once())
            ->method('text')
            ->with($this->callback(function ($data) {
                return is_array($data) && count($data) === 2;
            }));

        $config->render([$issue], $io);
    }
}
