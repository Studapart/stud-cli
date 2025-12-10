<?php

namespace App\Tests\Handler;

use App\DTO\Filter;
use App\Handler\FilterListHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterListHandlerTest extends CommandTestCase
{
    private FilterListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        // FilterListHandlerTest checks output text, so use real TranslationService
        // This is acceptable since FilterListHandler is the class under test
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new \App\Service\TranslationService('en', $translationsPath);

        TestKernel::$jiraService = $this->jiraService;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new FilterListHandler($this->jiraService, $this->translationService);
    }

    public function testHandle(): void
    {
        $filter = new Filter(
            'My Filter',
            'Filter description'
        );

        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willReturn([$filter]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Name', 'Description'],
                [['My Filter', 'Filter description']]
            );

        $this->handler->handle($io);
    }

    public function testHandleWithNoFiltersFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willReturn([]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('note')
            ->with('No filters found.');

        $this->handler->handle($io);
    }

    public function testHandleWithJiraServiceException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willThrowException(new \Exception('Jira API error'));

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('error')
            ->with('Failed to fetch filters: Jira API error');

        $this->handler->handle($io);
    }

    public function testHandleSortsFiltersByName(): void
    {
        $filter1 = new Filter('Zebra Filter', 'Description 1');
        $filter2 = new Filter('Alpha Filter', 'Description 2');
        $filter3 = new Filter('Beta Filter', 'Description 3');

        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willReturn([$filter1, $filter2, $filter3]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Name', 'Description'],
                [
                    ['Alpha Filter', 'Description 2'],
                    ['Beta Filter', 'Description 3'],
                    ['Zebra Filter', 'Description 1'],
                ]
            );

        $this->handler->handle($io);
    }

    public function testHandleWithNullDescription(): void
    {
        $filter = new Filter(
            'My Filter',
            null
        );

        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willReturn([$filter]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Name', 'Description'],
                [['My Filter', '']]
            );

        $this->handler->handle($io);
    }
}
