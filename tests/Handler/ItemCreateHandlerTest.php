<?php

namespace App\Tests\Handler;

use App\Exception\ApiException;
use App\Handler\ItemCreateHandler;
use App\Response\ItemCreateResponse;
use App\Service\GitRepository;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemCreateHandlerTest extends CommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->gitRepository = $this->createMock(GitRepository::class);
    }

    public function testHandleSuccessWithAllOptions(): void
    {
        $this->gitRepository->expects($this->never())->method('readProjectConfig');
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'description' => ['required' => false, 'name' => 'Description'],
            ]);
        $this->jiraService->expects($this->once())
            ->method('createIssue')
            ->with($this->callback(function ($fields) {
                return $fields['project']['key'] === 'PROJ'
                    && $fields['issuetype']['id'] === '10001'
                    && $fields['summary'] === 'My summary';
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, 'PROJ', 'Story', 'My summary', null);

        $this->assertInstanceOf(ItemCreateResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
        $this->assertSame('https://jira/issue/1', $response->self);
    }

    public function testHandleUsesProjectFromConfigWhenOptionNull(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['JIRA_DEFAULT_PROJECT' => 'CONF']);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('CONF')
            ->willReturn([['id' => '10002', 'name' => 'Story']]);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('CONF', '10002')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->jiraService->expects($this->once())
            ->method('createIssue')
            ->willReturn(['key' => 'CONF-1', 'self' => 'https://jira/issue/1']);

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, null, null, 'Summary', null);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('CONF-1', $response->key);
    }

    public function testHandleReturnsErrorWhenNoProjectAndNonInteractive(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);
        $this->jiraService->expects($this->never())->method('createIssue');

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, null, null, 'Summary', null);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_no_project', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenNoSummaryAndNonInteractive(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['JIRA_DEFAULT_PROJECT' => 'PROJ']);
        $this->jiraService->expects($this->never())->method('createIssue');

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, null, null, null, null);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_no_summary', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenExtraRequiredFields(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'customfield_10001' => ['required' => true, 'name' => 'Custom'],
            ]);
        $this->jiraService->expects($this->never())->method('createIssue');

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, 'PROJ', 'Story', 'Summary', null);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_extra_required', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenIssueTypeNotFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Task']]);
        $this->jiraService->expects($this->never())->method('getCreateMetaFields');
        $this->jiraService->expects($this->never())->method('createIssue');

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, 'PROJ', 'Story', 'Summary', null);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_createmeta', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenGetCreateMetaIssueTypesThrows(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willThrowException(new \RuntimeException('API unavailable'));
        $this->jiraService->expects($this->never())->method('getCreateMetaFields');
        $this->jiraService->expects($this->never())->method('createIssue');

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, 'PROJ', 'Story', 'Summary', null);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_createmeta', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenGetCreateMetaFieldsThrows(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willThrowException(new \RuntimeException('Fields API error'));
        $this->jiraService->expects($this->never())->method('createIssue');

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, 'PROJ', 'Story', 'Summary', null);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_createmeta', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenCreateIssueThrowsNonApiException(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->jiraService->expects($this->once())
            ->method('createIssue')
            ->willThrowException(new \RuntimeException('Network error'));

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, 'PROJ', 'Story', 'Summary', null);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_create', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenCreateIssueThrows(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->jiraService->expects($this->once())
            ->method('createIssue')
            ->willThrowException(new ApiException('API error', 'details', 400));

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, 'PROJ', 'Story', 'Summary', null);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_create', $response->getError() ?? '');
    }

    public function testHandleIncludesDescriptionWhenProvided(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'description' => ['required' => false, 'name' => 'Description'],
            ]);
        $this->jiraService->expects($this->once())
            ->method('plainTextToDescriptionAdf')
            ->with('Body text')
            ->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->jiraService->expects($this->once())
            ->method('createIssue')
            ->with($this->callback(function ($fields) {
                return isset($fields['description'])
                    && $fields['description'] === ['type' => 'doc', 'version' => 1, 'content' => []];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $io = $this->createMock(SymfonyStyle::class);
        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);

        $response = $handler->handle($io, false, 'PROJ', 'Story', 'Summary', 'Body text');

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleInteractivePromptsForProjectAndSummary(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->jiraService->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->jiraService->expects($this->once())
            ->method('createIssue')
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $io = $this->createMock(SymfonyStyle::class);
        $callCount = 0;
        $io->expects($this->exactly(2))
            ->method('ask')
            ->willReturnCallback(function () use (&$callCount) {
                ++$callCount;

                return $callCount === 1 ? 'PROJ' : 'My summary';
            });

        $handler = new ItemCreateHandler($this->gitRepository, $this->jiraService, $this->translationService);
        $response = $handler->handle($io, true, null, 'Story', null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }
}
