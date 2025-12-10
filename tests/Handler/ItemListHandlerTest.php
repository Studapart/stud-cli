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

        $this->handler->handle($io, false, null, null);
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

        $this->handler->handle($io, true, null, null);
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

        $this->handler->handle($io, false, 'MYPROJ', null);
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

        $this->handler->handle($io, true, 'MYPROJ', null);
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

        $result = $this->handler->handle($io, false, null, null);

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

        $result = $this->handler->handle($io, false, null, null);

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

        $result = $this->handler->handle($io, false, null, null);

        $this->assertSame(0, $result);
    }

    public function testHandleWithSortByKey(): void
    {
        $issue1 = new WorkItem(
            '1000',
            'TPW-100',
            'Feature A',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );
        $issue2 = new WorkItem(
            '1001',
            'TPW-10',
            'Feature B',
            'To Do',
            'Jane Doe',
            'description',
            ['tests'],
            'Task'
        );
        $issue3 = new WorkItem(
            '1002',
            'TPW-35',
            'Feature C',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([$issue1, $issue2, $issue3]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Key', 'Status', 'Summary'],
                [
                    ['TPW-10', 'To Do', 'Feature B'],
                    ['TPW-100', 'In Progress', 'Feature A'],
                    ['TPW-35', 'In Progress', 'Feature C'],
                ]
            );

        $this->handler->handle($io, false, null, 'Key');
    }

    public function testHandleWithSortByStatus(): void
    {
        $issue1 = new WorkItem(
            '1000',
            'TPW-35',
            'Feature A',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );
        $issue2 = new WorkItem(
            '1001',
            'TPW-10',
            'Feature B',
            'To Do',
            'Jane Doe',
            'description',
            ['tests'],
            'Task'
        );
        $issue3 = new WorkItem(
            '1002',
            'TPW-100',
            'Feature C',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([$issue1, $issue2, $issue3]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Key', 'Status', 'Summary'],
                [
                    ['TPW-35', 'In Progress', 'Feature A'],
                    ['TPW-100', 'In Progress', 'Feature C'],
                    ['TPW-10', 'To Do', 'Feature B'],
                ]
            );

        $this->handler->handle($io, false, null, 'Status');
    }

    public function testHandleWithSortCaseInsensitive(): void
    {
        $issue1 = new WorkItem(
            '1000',
            'TPW-100',
            'Feature A',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );
        $issue2 = new WorkItem(
            '1001',
            'TPW-10',
            'Feature B',
            'To Do',
            'Jane Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->exactly(2))
            ->method('searchIssues')
            ->with('assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([$issue1, $issue2]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->exactly(2))
            ->method('table')
            ->with(
                ['Key', 'Status', 'Summary'],
                [
                    ['TPW-10', 'To Do', 'Feature B'],
                    ['TPW-100', 'In Progress', 'Feature A'],
                ]
            );

        $this->handler->handle($io, false, null, 'key');
        $this->handler->handle($io, false, null, 'KEY');
    }

    public function testHandleWithSortNull(): void
    {
        $issue1 = new WorkItem(
            '1000',
            'TPW-100',
            'Feature A',
            'In Progress',
            'John Doe',
            'description',
            ['tests'],
            'Task'
        );
        $issue2 = new WorkItem(
            '1001',
            'TPW-10',
            'Feature B',
            'To Do',
            'Jane Doe',
            'description',
            ['tests'],
            'Task'
        );

        $this->jiraService->expects($this->once())
            ->method('searchIssues')
            ->with('assignee = currentUser() AND statusCategory in (\'To Do\', \'In Progress\') ORDER BY updated DESC')
            ->willReturn([$issue1, $issue2]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Key', 'Status', 'Summary'],
                [
                    ['TPW-100', 'In Progress', 'Feature A'],
                    ['TPW-10', 'To Do', 'Feature B'],
                ]
            );

        $this->handler->handle($io, false, null, null);
    }
}
