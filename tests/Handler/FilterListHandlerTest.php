<?php

namespace App\Tests\Handler;

use App\DTO\Filter;
use App\Handler\FilterListHandler;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterListHandlerTest extends CommandTestCase
{
    private FilterListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

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
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return is_array($headers) && count($headers) === 2;
                }),
                $this->callback(function ($rows) {
                    return is_array($rows) &&
                        count($rows) === 1 &&
                        count($rows[0]) === 2 &&
                        $rows[0][0] === 'My Filter' &&
                        $rows[0][1] === 'Filter description';
                })
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
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('note')
            ->with($this->callback(function ($message) {
                return is_string($message) && ! empty($message);
            }));

        $this->handler->handle($io);
    }

    public function testHandleWithJiraServiceException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willThrowException(new \Exception('Jira API error'));

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('error')
            ->with($this->callback(function ($message) {
                return is_string($message) && ! empty($message);
            }));

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
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return is_array($headers) && count($headers) === 2;
                }),
                $this->callback(function ($rows) {
                    return is_array($rows) &&
                        count($rows) === 3 &&
                        $rows[0][0] === 'Alpha Filter' &&
                        $rows[1][0] === 'Beta Filter' &&
                        $rows[2][0] === 'Zebra Filter';
                })
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
            ->method('section')
            ->with($this->anything());
        $io->expects($this->once())
            ->method('table')
            ->with(
                $this->callback(function ($headers) {
                    return is_array($headers) && count($headers) === 2;
                }),
                $this->callback(function ($rows) {
                    return is_array($rows) &&
                        count($rows) === 1 &&
                        count($rows[0]) === 2 &&
                        $rows[0][0] === 'My Filter' &&
                        $rows[0][1] === '';
                })
            );

        $this->handler->handle($io);
    }
}
