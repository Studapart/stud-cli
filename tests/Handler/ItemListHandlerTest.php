<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemListHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemListHandlerTest extends CommandTestCase
{
    private ItemListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        // ItemListHandlerTest checks output text, so use real TranslationService
        // This is acceptable since ItemListHandler is the class under test
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new \App\Service\TranslationService('en', $translationsPath);

        TestKernel::$jiraService = $this->jiraService;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new ItemListHandler($this->jiraService, $this->translationService);
    }

    public function testHandleDefault(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([$issue]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Key', 'Status', 'Summary'],
                [['TPW-35', 'In Progress', 'My awesome feature']]
            );

        $this->handler->handle($io, false, null);
    }

    public function testHandleAll(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([$issue]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Key', 'Status', 'Summary'],
                [['TPW-35', 'In Progress', 'My awesome feature']]
            );

        $this->handler->handle($io, true, null);
    }

    public function testHandleProject(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') AND project = MYPROJ ORDER BY updated DESC')
            ->willReturn([$issue]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Key', 'Status', 'Summary'],
                [['TPW-35', 'In Progress', 'My awesome feature']]
            );

        $this->handler->handle($io, false, 'MYPROJ');
    }

    public function testHandleAllAndProject(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('statusCategory in (\'To Do\', \'In Progress\') AND project = MYPROJ ORDER BY updated DESC')
            ->willReturn([$issue]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Key', 'Status', 'Summary'],
                [['TPW-35', 'In Progress', 'My awesome feature']]
            );

        $this->handler->handle($io, true, 'MYPROJ');
    }

    public function testHandleWithNoItemsFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->willReturn([]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('note')
            ->with('No items found matching your criteria.');

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
    }

    public function testHandleWithJiraServiceException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->willThrowException(new \Exception('Jira API error'));

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('error')
            ->with('Failed to fetch items: Jira API error');

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(1, $result);
    }

    public function testHandleWithVerboseOutput(): void
    {
        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with('  <fg=gray>JQL Query: assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC</>');
        $io->expects($this->once())
            ->method('note')
            ->with('No items found matching your criteria.');

        $result = $this->handler->handle($io, false, null);

        $this->assertSame(0, $result);
    }
}
