<?php

namespace App\Tests\Handler;

use App\DTO\Filter;
use App\Handler\FilterListHandler;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class FilterListHandlerTest extends CommandTestCase
{
    private FilterListHandler $handler;
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->handler = new FilterListHandler($this->jiraService, $this->translationService, $this->logger);
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

        $this->logger->expects($this->once())
            ->method('section')
            ->with(Logger::VERBOSITY_NORMAL, $this->anything());
        $this->logger->expects($this->once())
            ->method('table')
            ->with(
                Logger::VERBOSITY_NORMAL,
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

        $io = $this->createMock(SymfonyStyle::class);
        $this->handler->handle($io);
    }

    public function testHandleWithNoFiltersFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willReturn([]);

        $this->logger->expects($this->once())
            ->method('section')
            ->with(Logger::VERBOSITY_NORMAL, $this->anything());
        $this->logger->expects($this->once())
            ->method('note')
            ->with(Logger::VERBOSITY_NORMAL, $this->callback(function ($message) {
                return is_string($message) && ! empty($message);
            }));

        $io = $this->createMock(SymfonyStyle::class);
        $this->handler->handle($io);
    }

    public function testHandleWithJiraServiceException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getFilters')
            ->willThrowException(new \Exception('Jira API error'));

        $this->logger->expects($this->once())
            ->method('section')
            ->with(Logger::VERBOSITY_NORMAL, $this->anything());
        $this->logger->expects($this->once())
            ->method('error')
            ->with(Logger::VERBOSITY_NORMAL, $this->callback(function ($message) {
                return is_string($message) && ! empty($message);
            }));

        $io = $this->createMock(SymfonyStyle::class);
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

        $this->logger->expects($this->once())
            ->method('section')
            ->with(Logger::VERBOSITY_NORMAL, $this->anything());
        $this->logger->expects($this->once())
            ->method('table')
            ->with(
                Logger::VERBOSITY_NORMAL,
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

        $io = $this->createMock(SymfonyStyle::class);
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

        $this->logger->expects($this->once())
            ->method('section')
            ->with(Logger::VERBOSITY_NORMAL, $this->anything());
        $this->logger->expects($this->once())
            ->method('table')
            ->with(
                Logger::VERBOSITY_NORMAL,
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

        $io = $this->createMock(SymfonyStyle::class);
        $this->handler->handle($io);
    }
}
