<?php

namespace App\Tests\Handler;

use App\DTO\ItemCreateInput;
use App\DTO\Project;
use App\Exception\ApiException;
use App\Handler\ItemCreateHandler;
use App\Response\ItemCreateResponse;
use App\Service\DurationParser;
use App\Service\FieldsParser;
use App\Service\GitRepository;
use App\Service\IssueFieldResolver;
use App\Service\ItemCreateProjectResolver;
use App\Service\ItemCreatePromptService;
use App\Service\Prompt\PromptInterface;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ItemCreateHandlerTest extends CommandTestCase
{
    private IssueFieldResolver $fieldResolver;
    private FieldsParser $fieldsParser;
    private PromptInterface&MockObject $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->fieldResolver = new IssueFieldResolver($this->jiraApiClient, new DurationParser());
        $this->fieldsParser = new FieldsParser(new DurationParser());
        $this->prompt = $this->createMock(PromptInterface::class);
    }

    private function createHandler(): ItemCreateHandler
    {
        return new ItemCreateHandler(
            new ItemCreateProjectResolver($this->gitRepository, $this->jiraApiClient, $this->prompt),
            new ItemCreatePromptService($this->jiraApiClient, $this->fieldResolver, $this->prompt),
            $this->issueTracker,
            $this->fieldResolver,
            $this->fieldsParser,
            $this->prompt,
        );
    }

    public function testHandleSuccessWithAllOptions(): void
    {
        $this->gitRepository->expects($this->never())->method('readProjectConfig');
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'description' => ['required' => false, 'name' => 'Description'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($fields) {
                return $fields['project']['key'] === 'PROJ'
                    && $fields['issuetype']['id'] === '10001'
                    && $fields['summary'] === 'My summary';
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

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
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('CONF')
            ->willReturn(new Project('CONF', 'Config Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('CONF')
            ->willReturn([['id' => '10002', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('CONF', '10002')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->willReturn(['key' => 'CONF-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput(null, null, 'Summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('CONF-1', $response->key);
    }

    public function testHandleReturnsErrorWhenNoProjectAndNonInteractive(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);
        $this->issueTracker->expects($this->never())->method('create');

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput(null, null, 'Summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_no_project', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenNoSummaryAndNonInteractive(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['JIRA_DEFAULT_PROJECT' => 'PROJ']);
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->issueTracker->expects($this->never())->method('create');

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput(null, null, null, null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_no_summary', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenExtraRequiredFields(): void
    {
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'customfield_10001' => ['required' => true, 'name' => 'Custom'],
        ];
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->issueTracker->expects($this->never())->method('create');

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'Summary', null));

        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'item.create.error_extra_required');
        $this->assertStringContainsString('Custom (customfield_10001)', (string) $message->parameters['fields']);
    }

    public function testHandleReturnsErrorWhenIssueTypeNotFound(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Task']]);
        $this->issueTracker->expects($this->never())->method('getCreateMetaFields');
        $this->issueTracker->expects($this->never())->method('create');

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'Summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_createmeta', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenGetCreateMetaIssueTypesThrows(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willThrowException(new \RuntimeException('API unavailable'));
        $this->issueTracker->expects($this->never())->method('getCreateMetaFields');
        $this->issueTracker->expects($this->never())->method('create');

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'Summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_createmeta', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenGetCreateMetaFieldsThrows(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willThrowException(new \RuntimeException('Fields API error'));
        $this->issueTracker->expects($this->never())->method('create');

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'Summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_createmeta', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenCreateIssueThrowsNonApiException(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->willThrowException(new \RuntimeException('Network error'));

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'Summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_create', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenCreateIssueThrows(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->willThrowException(new ApiException('API error', 'details', 400));

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'Summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_create', $response->getError() ?? '');
    }

    public function testHandleIncludesDescriptionWhenProvided(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'description' => ['required' => false, 'name' => 'Description'],
            ]);
        $this->jiraApiClient->expects($this->once())
            ->method('descriptionToAdf')
            ->with('Body text', 'plain')
            ->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($fields) {
                return isset($fields['description'])
                    && $fields['description'] === ['type' => 'doc', 'version' => 1, 'content' => []];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'Summary', 'Body text'));

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleWithParentCreatesSubTaskWithParentKey(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10002', 'name' => 'Sub-task']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10002')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'parent' => ['required' => true, 'name' => 'Parent'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($fields) {
                return isset($fields['parent']['key'])
                    && $fields['parent']['key'] === 'PROJ-100'
                    && isset($fields['issuetype']['id'])
                    && $fields['issuetype']['id'] === '10002';
            }))
            ->willReturn(['key' => 'PROJ-101', 'self' => 'https://jira/issue/101']);

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput(project: 'PROJ', type: null, summary: 'Sub-task summary', descriptionOption: null, parentKey: 'PROJ-100'));

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleInteractivePromptsForProjectAndSummary(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $callCount = 0;
        $this->prompt->expects($this->exactly(2))
            ->method('ask')
            ->willReturnCallback(function () use (&$callCount) {
                ++$callCount;

                return $callCount === 1 ? 'PROJ' : 'My summary';
            });

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput(null, 'Story', null, null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleInteractivePromptsForExtraRequiredFieldsThenCreates(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'customfield_10001' => ['required' => true, 'name' => 'Team'],
        ];
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return $fields['project']['key'] === 'PROJ'
                    && $fields['issuetype']['id'] === '10001'
                    && $fields['summary'] === 'My summary'
                    && isset($fields['customfield_10001'])
                    && $fields['customfield_10001'] === 'Alpha';
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $this->prompt->expects($this->once())
            ->method('ask')
            ->with($this->anything())
            ->willReturn('Alpha');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleInteractiveExtraRequiredWhenGetCreateMetaFieldsThrowsReturnsError(): void
    {
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'customfield_10001' => ['required' => true, 'name' => 'Team'],
        ];
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->exactly(2))
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('API error')),
                $fieldsMeta
            );
        $this->issueTracker->expects($this->never())->method('create');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_extra_required', $response->getError() ?? '');
    }

    public function testHandleInteractiveNumericCustomFieldIdSentAsCustomfieldPrefix(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Task']]);
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            '15' => ['required' => true, 'name' => 'Team'],
        ];
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return $fields['project']['key'] === 'PROJ'
                    && $fields['issuetype']['id'] === '10001'
                    && $fields['summary'] === 'My summary'
                    && isset($fields['customfield_15'])
                    && $fields['customfield_15'] === 'Alpha';
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $this->prompt->expects($this->once())
            ->method('ask')
            ->with($this->anything())
            ->willReturn('Alpha');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('PROJ', 'Task', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleInteractiveSkipsEmptyExtraRequiredFieldValues(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'customfield_10001' => ['required' => true, 'name' => 'Team'],
            'customfield_10002' => ['required' => true, 'name' => 'Sprint'],
        ];
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return $fields['project']['key'] === 'PROJ'
                    && $fields['issuetype']['id'] === '10001'
                    && $fields['summary'] === 'My summary'
                    && ! isset($fields['customfield_10001'])
                    && isset($fields['customfield_10002'])
                    && $fields['customfield_10002'] === 'Sprint1';
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $callCount = 0;
        $this->prompt->expects($this->exactly(2))
            ->method('ask')
            ->willReturnCallback(function () use (&$callCount) {
                ++$callCount;

                return $callCount === 1 ? '' : 'Sprint1';
            });

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleInteractiveExtraRequiredProjectInferredFromKeyNoPrompt(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $fieldsMeta = [
            'Project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
        ];
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['project']) && $fields['project'] === ['key' => 'PROJ'];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $this->prompt->expects($this->never())->method('ask');
        $this->prompt->expects($this->never())->method('choice');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleInteractiveExtraRequiredReporterDefaultsToCurrentUserNoPrompt(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'reporter' => ['required' => true, 'name' => 'Reporter'],
        ];
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->once())
            ->method('getCurrentUserAccountId')
            ->willReturn('current-user-account-id');
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['reporter'])
                    && $fields['reporter'] === ['accountId' => 'current-user-account-id'];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $this->prompt->expects($this->never())->method('ask');
        $this->prompt->expects($this->never())->method('choice');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleRequiredAssigneeDefaultsToCurrentUserWhenOptionNull(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'assignee' => ['required' => true, 'name' => 'Assignee'],
            ]);
        $this->jiraApiClient->expects($this->once())
            ->method('getCurrentUserAccountId')
            ->willReturn('current-user-account-id');
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['assignee'])
                    && $fields['assignee'] === ['accountId' => 'current-user-account-id'];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleRequiredAssigneeUsesFieldsOptionWhenProvided(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'assignee' => ['required' => true, 'name' => 'Assignee'],
            ]);
        $this->jiraApiClient->expects($this->once())
            ->method('getCurrentUserAccountId')
            ->willReturn('current-user-account-id');
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['assignee'])
                    && $fields['assignee'] === ['id' => 'custom-assignee-account-id'];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null, fieldsOption: 'assignee=custom-assignee-account-id'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleOptionalAssigneeDefaultsToCurrentUserWhenFieldPresent(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'assignee' => ['required' => false, 'name' => 'Assignee'],
            ]);
        $this->jiraApiClient->expects($this->once())
            ->method('getCurrentUserAccountId')
            ->willReturn('current-user-account-id');
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['assignee'])
                    && $fields['assignee'] === ['accountId' => 'current-user-account-id'];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleOptionalAssigneeUsesFieldsOptionWhenProvided(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'assignee' => ['required' => false, 'name' => 'Assignee'],
            ]);
        $this->jiraApiClient->expects($this->once())
            ->method('getCurrentUserAccountId')
            ->willReturn('current-user-account-id');
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['assignee'])
                    && $fields['assignee'] === ['id' => 'optional-assignee-id'];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null, fieldsOption: 'assignee=optional-assignee-id'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleInteractiveExtraRequiredIssueTypeAndSummaryTakenFromResolvedNoPrompt(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story'], ['id' => '10002', 'name' => 'Task']]);
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'Issue Type' => ['required' => true, 'name' => 'Issue Type'],
            'Summary' => ['required' => true, 'name' => 'Summary'],
        ];
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['issuetype']) && $fields['issuetype'] === ['id' => '10001']
                    && isset($fields['summary']) && $fields['summary'] === 'My summary';
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $this->prompt->expects($this->never())->method('ask');
        $this->prompt->expects($this->never())->method('choice');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleInteractiveNoTypeProvidedShowsIssueTypeChoice(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $issueTypes = [['id' => '10001', 'name' => 'Story'], ['id' => '10002', 'name' => 'Task']];
        $this->jiraApiClient->expects($this->exactly(2))
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn($issueTypes);
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'Issue Type' => ['required' => true, 'name' => 'Issue Type'],
        ];
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['issuetype']) && $fields['issuetype'] === ['id' => '10002'];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $this->prompt->expects($this->never())->method('ask');
        $this->prompt->expects($this->once())
            ->method('choice')
            ->with($this->anything(), ['Story', 'Task'], $this->anything())
            ->willReturn('Task');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('PROJ', null, 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleReturnsErrorWhenProjectNotFoundAndNonInteractive(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('INVALID')
            ->willThrowException(new ApiException('Project "INVALID" not found.', 'details', 404));
        $this->jiraApiClient->expects($this->never())->method('getCreateMetaIssueTypes');
        $this->issueTracker->expects($this->never())->method('create');

        $handler = $this->createHandler();

        $response = $handler->handle(false, new ItemCreateInput('INVALID', 'Story', 'Summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_project_not_found', $response->getError() ?? '');
    }

    public function testHandleInteractiveProjectNotFoundPromptsForKeyThenSucceeds(): void
    {
        $this->jiraApiClient->expects($this->exactly(2))
            ->method('getProject')
            ->willReturnCallback(function (string $key) {
                if ($key === 'BAD') {
                    throw new ApiException('Not found', 'details', 404);
                }

                return new Project('PROJ', 'Project');
            });
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $this->prompt->expects($this->once())
            ->method('ask')
            ->with($this->callback(fn (mixed $message): bool => $message instanceof \App\DTO\MessageRef
        && $message->key === 'item.create.prompt_project_not_found'))
            ->willReturn('PROJ');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('BAD', 'Story', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandleInteractiveProjectNotFoundAndUserGivesEmptyKeyReturnsError(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('BAD')
            ->willThrowException(new ApiException('Not found', 'details', 404));
        $this->issueTracker->expects($this->never())->method('getCreateMetaFields');
        $this->issueTracker->expects($this->never())->method('create');

        $this->prompt->expects($this->once())
            ->method('ask')
            ->with($this->callback(fn (mixed $message): bool => $message instanceof \App\DTO\MessageRef
        && $message->key === 'item.create.prompt_project_not_found'))
            ->willReturn('');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('BAD', 'Story', 'My summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_project_not_found', $response->getError() ?? '');
    }

    public function testHandleInteractiveProjectNotFoundAndRetryKeyAlsoNotFoundReturnsError(): void
    {
        $this->jiraApiClient->expects($this->exactly(2))
            ->method('getProject')
            ->willReturnCallback(function (string $key) {
                if ($key === 'BAD' || $key === 'ALSO_BAD') {
                    throw new ApiException('Not found', 'details', 404);
                }

                return new Project($key, 'Project');
            });
        $this->issueTracker->expects($this->never())->method('getCreateMetaFields');
        $this->issueTracker->expects($this->never())->method('create');

        $this->prompt->expects($this->once())
            ->method('ask')
            ->with($this->callback(fn (mixed $message): bool => $message instanceof \App\DTO\MessageRef
        && $message->key === 'item.create.prompt_project_not_found'))
            ->willReturn('ALSO_BAD');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('BAD', 'Story', 'My summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_project_not_found', $response->getError() ?? '');
    }

    public function testHandleReturnsErrorWhenExtraRequiredAndGetMetaThrowsWhenBuildingFieldList(): void
    {
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'customfield_10001' => ['required' => true, 'name' => 'Custom'],
        ];
        $callCount = 0;
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturnCallback(function () use ($fieldsMeta, &$callCount) {
                ++$callCount;
                if ($callCount === 2) {
                    throw new \RuntimeException('Second call fails');
                }

                return $fieldsMeta;
            });
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->never())->method('create');

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'Summary', null));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.create.error_extra_required', $response->getError() ?? '');
    }

    public function testHandleInteractiveExtraRequiredDescriptionPromptsAndFills(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $fieldsMeta = [
            'project' => ['required' => true, 'name' => 'Project'],
            'issuetype' => ['required' => true, 'name' => 'Issue Type'],
            'summary' => ['required' => true, 'name' => 'Summary'],
            'description' => ['required' => true, 'name' => 'Description'],
        ];
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn($fieldsMeta);
        $this->jiraApiClient->expects($this->once())
            ->method('plainTextToDescriptionAdf')
            ->with('Typed description')
            ->willReturn(['type' => 'doc', 'content' => []]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['description']) && $fields['description'] === ['type' => 'doc', 'content' => []];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $this->prompt->expects($this->once())
            ->method('ask')
            ->with($this->callback(fn (mixed $message): bool => $message instanceof \App\DTO\MessageRef
        && $message->key === 'item.create.prompt_description_required'))
            ->willReturn('Typed description');

        $handler = $this->createHandler();
        $response = $handler->handle(true, new ItemCreateInput('PROJ', 'Story', 'My summary', null));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testPromptForExtraRequiredFieldsProjectFillsKey(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn(['project' => ['required' => true, 'name' => 'Project']]);
        $handler = $this->createHandler();
        $result = $this->callPrivateMethod($handler, 'promptForExtraRequiredFields', [true, 'PROJ', '10001', false, 'Summary', null, ['project'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame(['project' => ['key' => 'PROJ']], $result);
    }

    public function testPromptForExtraRequiredFieldsReporterDefaultsToCurrentUser(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn(['reporter' => ['required' => true, 'name' => 'Reporter']]);
        $this->jiraApiClient->expects($this->once())
            ->method('getCurrentUserAccountId')
            ->willReturn('current-user-id');
        $handler = $this->createHandler();
        $result = $this->callPrivateMethod($handler, 'promptForExtraRequiredFields', [true, 'PROJ', '10001', false, 'Summary', null, ['reporter'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame(['reporter' => ['accountId' => 'current-user-id']], $result);
    }

    public function testPromptForExtraRequiredFieldsAssigneeDefaultsToCurrentUser(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn(['assignee' => ['required' => true, 'name' => 'Assignee']]);
        $this->jiraApiClient->expects($this->once())
            ->method('getCurrentUserAccountId')
            ->willReturn('current-user-id');
        $handler = $this->createHandler();
        $result = $this->callPrivateMethod($handler, 'promptForExtraRequiredFields', [true, 'PROJ', '10001', false, 'Summary', null, ['assignee'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame(['assignee' => ['accountId' => 'current-user-id']], $result);
    }

    public function testPromptForExtraRequiredFieldsIssueTypeWhenTypeExplicitlyProvided(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'Issue Type' => ['required' => true, 'name' => 'Issue Type'],
            ]);
        $handler = $this->createHandler();
        $result = $this->callPrivateMethod($handler, 'promptForExtraRequiredFields', [true, 'PROJ', '10001', true, 'Summary', null, ['issuetype'],
        ]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('issuetype', $result);
        $this->assertSame(['id' => '10001'], $result['issuetype']);
    }

    public function testPromptForExtraRequiredFieldsSummaryFillsFromArgument(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn(['summary' => ['required' => true, 'name' => 'Summary']]);
        $this->prompt->expects($this->never())->method('ask');
        $handler = $this->createHandler();
        $result = $this->callPrivateMethod($handler, 'promptForExtraRequiredFields', [true, 'PROJ', '10001', false, 'My Title', null, ['summary'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame(['summary' => 'My Title'], $result);
    }

    public function testPromptForExtraRequiredFieldsDescriptionWhenAdfProvided(): void
    {
        $descriptionAdf = ['type' => 'doc', 'version' => 1, 'content' => []];
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn(['description' => ['required' => true, 'name' => 'Description']]);
        $this->prompt->expects($this->never())->method('ask');
        $handler = $this->createHandler();
        $result = $this->callPrivateMethod($handler, 'promptForExtraRequiredFields', [true, 'PROJ', '10001', false, 'Summary', $descriptionAdf, ['description'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame(['description' => $descriptionAdf], $result);
    }

    public function testPromptForExtraRequiredFieldsDescriptionPromptWhenDescriptionAdfNull(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'description' => ['required' => true, 'name' => 'Description'],
            ]);
        $this->jiraApiClient->expects($this->once())
            ->method('plainTextToDescriptionAdf')
            ->with('User typed description')
            ->willReturn(['type' => 'doc', 'content' => []]);
        $this->prompt->expects($this->once())
            ->method('ask')
            ->with($this->callback(fn (mixed $message): bool => $message instanceof \App\DTO\MessageRef
        && $message->key === 'item.create.prompt_description_required'))
            ->willReturn('User typed description');
        $handler = $this->createHandler();
        $result = $this->callPrivateMethod($handler, 'promptForExtraRequiredFields', [true, 'PROJ',
            '10001',
            false,
            'Summary',
            null,
            ['description'],
        ]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('description', $result);
        $this->assertSame(['type' => 'doc', 'content' => []], $result['description']);
    }

    public function testHandleRequiredDescriptionAndDescriptionProvidedFillsDescription(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('PROJ')
            ->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'description' => ['required' => true, 'name' => 'Description'],
            ]);
        $this->jiraApiClient->expects($this->once())
            ->method('descriptionToAdf')
            ->with('Body text', 'plain')
            ->willReturn(['type' => 'doc', 'content' => []]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['description']) && $fields['description'] === ['type' => 'doc', 'content' => []];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'Summary', 'Body text'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
    }

    public function testHandle_labelsWhenCreatemetaHasLabels_addsToPayload(): void
    {
        $this->gitRepository->expects($this->never())->method('readProjectConfig');
        $this->jiraApiClient->expects($this->once())->method('getProject')->with('PROJ')->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())->method('getCreateMetaIssueTypes')->with('PROJ')->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'labels' => ['required' => false, 'name' => 'Labels'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['labels']) && $fields['labels'] === ['a', 'b'];
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null, fieldsOption: 'labels=a,b'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
        $this->assertSame([], $response->skippedOptionalFields ?? []);
    }

    public function testHandle_labelsWhenCreatemetaDoesNotHaveLabels_skippedAndCreateSucceeds(): void
    {
        $this->gitRepository->expects($this->never())->method('readProjectConfig');
        $this->jiraApiClient->expects($this->once())->method('getProject')->with('PROJ')->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())->method('getCreateMetaIssueTypes')->with('PROJ')->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return ! isset($fields['labels']);
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null, fieldsOption: 'labels=a,b'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
        $this->assertNotNull($response->skippedOptionalFields);
        $this->assertContains('labels', $response->skippedOptionalFields);
    }

    public function testHandle_originalEstimateWhenCreatemetaHasIt_addsToPayload(): void
    {
        $this->gitRepository->expects($this->never())->method('readProjectConfig');
        $this->jiraApiClient->expects($this->once())->method('getProject')->with('PROJ')->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())->method('getCreateMetaIssueTypes')->with('PROJ')->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'timeoriginalestimate' => ['required' => false, 'name' => 'Time Original Estimate'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['timeoriginalestimate']) && $fields['timeoriginalestimate'] === 86400;
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null, fieldsOption: 'timeoriginalestimate=1d'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
        $this->assertSame([], $response->skippedOptionalFields ?? []);
    }

    public function testHandle_originalEstimateWhenCreatemetaDoesNotHaveIt_skippedAndCreateSucceeds(): void
    {
        $this->gitRepository->expects($this->never())->method('readProjectConfig');
        $this->jiraApiClient->expects($this->once())->method('getProject')->with('PROJ')->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())->method('getCreateMetaIssueTypes')->with('PROJ')->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return ! isset($fields['timeoriginalestimate']);
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null, fieldsOption: 'timeoriginalestimate=1d'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PROJ-1', $response->key);
        $this->assertNotNull($response->skippedOptionalFields);
        $this->assertContains('timeoriginalestimate', $response->skippedOptionalFields);
    }

    public function testHandle_invalidOriginalEstimate_passedThroughAsRawValue(): void
    {
        $this->gitRepository->expects($this->never())->method('readProjectConfig');
        $this->jiraApiClient->expects($this->once())->method('getProject')->with('PROJ')->willReturn(new Project('PROJ', 'Project'));
        $this->jiraApiClient->expects($this->once())->method('getCreateMetaIssueTypes')->with('PROJ')->willReturn([['id' => '10001', 'name' => 'Story']]);
        $this->issueTracker->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('PROJ', '10001')
            ->willReturn([
                'project' => ['required' => true, 'name' => 'Project'],
                'issuetype' => ['required' => true, 'name' => 'Issue Type'],
                'summary' => ['required' => true, 'name' => 'Summary'],
                'timeoriginalestimate' => ['required' => false, 'name' => 'Time Original Estimate'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return isset($fields['timeoriginalestimate'])
                    && $fields['timeoriginalestimate'] === 'invalid';
            }))
            ->willReturn(['key' => 'PROJ-1', 'self' => 'https://jira/issue/1']);

        $handler = $this->createHandler();
        $response = $handler->handle(false, new ItemCreateInput('PROJ', 'Story', 'My summary', null, fieldsOption: 'timeoriginalestimate=invalid'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->skippedOptionalFields ?? []);
    }

    public function testPromptIssueTypeValueReturnsNullWhenChooseIssueTypeReturnsNull(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([]);

        $handler = $this->createHandler();

        $result = $this->callPrivateMethod($handler, 'promptIssueTypeValue', [
            'PROJ',
            '10001',
            false,
            ['issuetype' => ['name' => 'Issue Type']],
        ]);

        $this->assertNull($result);
    }

    public function testChooseIssueTypeInteractivelyReturnsNullWhenNoIssueTypes(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([]);

        $handler = $this->createHandler();

        $result = $this->callPrivateMethod($handler, 'chooseIssueTypeInteractively', ['PROJ']);

        $this->assertNull($result);
    }

    public function testChooseIssueTypeInteractivelyReturnsNullWhenChoiceDoesNotMatchAnyName(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('PROJ')
            ->willReturn([['id' => '10001', 'name' => 'Story'], ['id' => '10002', 'name' => 'Bug']]);

        $this->prompt->expects($this->once())
            ->method('choice')
            ->willReturn('Other');

        $handler = $this->createHandler();

        $result = $this->callPrivateMethod($handler, 'chooseIssueTypeInteractively', ['PROJ']);

        $this->assertNull($result);
    }

    public function testPromptDescriptionValueReturnsNullWhenAskReturnsEmpty(): void
    {
        $this->prompt->expects($this->once())
            ->method('ask')
            ->willReturn('   ');

        $handler = $this->createHandler();

        $result = $this->callPrivateMethod($handler, 'promptDescriptionValue', [null]);

        $this->assertNull($result);
    }
}
