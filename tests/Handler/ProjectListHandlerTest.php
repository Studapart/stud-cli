<?php

namespace App\Tests\Handler;

use App\DTO\Project;
use App\Handler\ProjectListHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectListHandlerTest extends CommandTestCase
{
    private ProjectListHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        // ProjectListHandlerTest checks output text, so use real TranslationService
        // This is acceptable since ProjectListHandler is the class under test
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new \App\Service\TranslationService('en', $translationsPath);

        TestKernel::$jiraService = $this->jiraService;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new ProjectListHandler($this->jiraService, $this->translationService);
    }

    public function testHandle(): void
    {
        $project = new Project(
            'PROJ',
            'My Project'
        );

        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willReturn([$project]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('table')
            ->with(
                ['Key', 'Name'],
                [['PROJ', 'My Project']]
            );

        $this->handler->handle($io);
    }

    public function testHandleWithNoProjectsFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willReturn([]);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('note')
            ->with('No projects found.');

        $this->handler->handle($io);
    }

    public function testHandleWithJiraServiceException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willThrowException(new \Exception('Jira API error'));

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('error')
            ->with('Failed to fetch projects: Jira API error');

        $this->handler->handle($io);
    }
}
