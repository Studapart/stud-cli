<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\Filter;
use App\DTO\IssueAttachment;
use App\DTO\Project;
use App\DTO\StateChange;
use App\DTO\WorkItem;
use App\Exception\ApiException;
use App\Service\JiraApiClient;
use App\Service\JiraAttachmentService;
use App\Service\JiraIssueTrackerAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class JiraIssueTrackerAdapterTest extends TestCase
{
    private JiraApiClient&MockObject $jiraApiClient;

    private JiraAttachmentService&MockObject $attachmentService;

    private JiraIssueTrackerAdapter $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->attachmentService = $this->createMock(JiraAttachmentService::class);
        $this->provider = new JiraIssueTrackerAdapter($this->jiraApiClient, $this->attachmentService);
    }

    public function testGetIssueDelegatesToJiraApiClient(): void
    {
        $workItem = new WorkItem('10001', 'SCI-1', 'Title', 'Open', 'User', 'Desc', [], 'Task');
        $this->jiraApiClient->expects($this->once())
            ->method('getIssue')
            ->with('SCI-1', true)
            ->willReturn($workItem);

        $this->assertSame($workItem, $this->provider->getIssue('SCI-1', true));
    }

    public function testSearchDelegatesToJiraApiClient(): void
    {
        $issues = [new WorkItem('10001', 'SCI-1', 'Title', 'Open', 'User', 'Desc', [], 'Task')];
        $this->jiraApiClient->expects($this->once())
            ->method('searchIssues')
            ->with('project = SCI')
            ->willReturn($issues);

        $this->assertSame($issues, $this->provider->search('project = SCI'));
    }

    public function testListAssignedActiveBuildsJqlWithoutProject(): void
    {
        $issues = [];
        $this->jiraApiClient->expects($this->once())
            ->method('searchIssues')
            ->with("assignee = currentUser() AND statusCategory in ('To Do', 'In Progress') ORDER BY updated DESC")
            ->willReturn($issues);

        $this->assertSame($issues, $this->provider->listAssignedActive());
    }

    public function testListAssignedActiveBuildsJqlWithProject(): void
    {
        $issues = [];
        $this->jiraApiClient->expects($this->once())
            ->method('searchIssues')
            ->with("assignee = currentUser() AND statusCategory in ('To Do', 'In Progress') AND project = SCI ORDER BY updated DESC")
            ->willReturn($issues);

        $this->assertSame($issues, $this->provider->listAssignedActive('sci'));
    }

    public function testListAssignedActiveWithoutOnlyMineOmitsAssigneeClause(): void
    {
        $issues = [];
        $this->jiraApiClient->expects($this->once())
            ->method('searchIssues')
            ->with("statusCategory in ('To Do', 'In Progress') ORDER BY updated DESC")
            ->willReturn($issues);

        $this->assertSame($issues, $this->provider->listAssignedActive(null, false));
    }

    public function testCreateDelegatesToJiraApiClient(): void
    {
        $fields = ['project' => ['key' => 'SCI']];
        $created = ['key' => 'SCI-2', 'self' => 'https://jira.example.com/browse/SCI-2'];
        $this->jiraApiClient->expects($this->once())
            ->method('createIssue')
            ->with($fields)
            ->willReturn($created);

        $this->assertSame($created, $this->provider->create($fields));
    }

    public function testUpdateDelegatesToJiraApiClient(): void
    {
        $fields = ['summary' => 'Updated'];
        $this->jiraApiClient->expects($this->once())
            ->method('updateIssue')
            ->with('SCI-1', $fields);

        $this->provider->update('SCI-1', $fields);
        $this->addToAssertionCount(1);
    }

    public function testGetCreateMetaFieldsDelegatesToJiraApiClient(): void
    {
        $meta = ['summary' => ['required' => true, 'name' => 'Summary']];
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaFields')
            ->with('SCI', '10001')
            ->willReturn($meta);

        $this->assertSame($meta, $this->provider->getCreateMetaFields('SCI', '10001'));
    }

    public function testGetEditMetaFieldsDelegatesToJiraApiClient(): void
    {
        $meta = ['summary' => ['required' => false, 'name' => 'Summary']];
        $this->jiraApiClient->expects($this->once())
            ->method('getEditMetaFields')
            ->with('SCI-1')
            ->willReturn($meta);

        $this->assertSame($meta, $this->provider->getEditMetaFields('SCI-1'));
    }

    public function testFormatDescriptionDelegatesToJiraApiClient(): void
    {
        $adf = ['type' => 'doc', 'version' => 1, 'content' => []];
        $this->jiraApiClient->expects($this->once())
            ->method('descriptionToAdf')
            ->with('Hello', 'markdown')
            ->willReturn($adf);

        $this->assertSame($adf, $this->provider->formatDescription('Hello', 'markdown'));
    }

    public function testListProjectStateChangesMapsTransitions(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProjectTransitions')
            ->with('SCI')
            ->willReturn([
                ['id' => 21, 'name' => 'Start', 'to' => ['name' => 'In Progress']],
            ]);

        $changes = $this->provider->listProjectStateChanges('SCI');

        $this->assertCount(1, $changes);
        $this->assertInstanceOf(StateChange::class, $changes[0]);
        $this->assertSame('21', $changes[0]->id);
        $this->assertSame('Start', $changes[0]->name);
        $this->assertSame('In Progress', $changes[0]->targetStatus);
    }

    public function testListItemStateChangesMapsTransitions(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getTransitions')
            ->with('SCI-1')
            ->willReturn([
                ['id' => 31, 'name' => 'Done', 'to' => ['name' => 'Done']],
            ]);

        $changes = $this->provider->listItemStateChanges('SCI-1');

        $this->assertCount(1, $changes);
        $this->assertSame('31', $changes[0]->id);
    }

    public function testApplyStateChangeDelegatesToJiraApiClient(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('transitionIssue')
            ->with('SCI-1', 21);

        $this->provider->applyStateChange('SCI-1', '21');
        $this->addToAssertionCount(1);
    }

    public function testAssignUsesCurrentUserWhenUserIsNull(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('assignIssue')
            ->with('SCI-1', 'currentUser()');

        $this->provider->assign('SCI-1');
        $this->addToAssertionCount(1);
    }

    public function testAssignDelegatesExplicitUser(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('assignIssue')
            ->with('SCI-1', 'account-123');

        $this->provider->assign('SCI-1', 'account-123');
        $this->addToAssertionCount(1);
    }

    public function testListTeamsDelegatesToGetProjects(): void
    {
        $projects = [new Project('SCI', 'Stud CLI')];
        $this->jiraApiClient->expects($this->once())
            ->method('getProjects')
            ->willReturn($projects);

        $this->assertSame($projects, $this->provider->listTeams());
    }

    public function testListFiltersOrViewsDelegatesToGetFilters(): void
    {
        $filters = [new Filter('My filter', 'assignee = currentUser()')];
        $this->jiraApiClient->expects($this->once())
            ->method('getFilters')
            ->willReturn($filters);

        $this->assertSame($filters, $this->provider->listFiltersOrViews());
    }

    public function testRunFilterOrViewBuildsJqlAndSearches(): void
    {
        $issues = [new WorkItem('10001', 'SCI-1', 'Title', 'Open', 'User', 'Desc', [], 'Task')];
        $this->jiraApiClient->expects($this->once())
            ->method('searchIssues')
            ->with('filter = "My Filter"')
            ->willReturn($issues);

        $this->assertSame($issues, $this->provider->runFilterOrView('My Filter'));
    }

    public function testListWorkflowMetadataReturnsIssueTypesForProject(): void
    {
        $issueTypes = [['id' => '10001', 'name' => 'Story']];
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('SCI')
            ->willReturn($issueTypes);

        $this->assertSame(['issueTypes' => $issueTypes], $this->provider->listWorkflowMetadata('SCI'));
    }

    public function testListWorkflowMetadataReturnsEmptyWhenProjectMissing(): void
    {
        $this->jiraApiClient->expects($this->never())->method('getCreateMetaIssueTypes');

        $this->assertSame([], $this->provider->listWorkflowMetadata());
    }

    public function testListTypeLabelsReturnsIssueTypeNames(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getCreateMetaIssueTypes')
            ->with('SCI')
            ->willReturn([
                ['id' => '1', 'name' => 'Story'],
                ['id' => '2', 'name' => 'Bug'],
            ]);

        $this->assertSame(['Story', 'Bug'], $this->provider->listTypeLabels('SCI'));
    }

    public function testListTypeLabelsReturnsEmptyWhenProjectMissing(): void
    {
        $this->jiraApiClient->expects($this->never())->method('getCreateMetaIssueTypes');

        $this->assertSame([], $this->provider->listTypeLabels());
    }

    public function testPingDelegatesToGetProjects(): void
    {
        $this->jiraApiClient->expects($this->once())
            ->method('getProjects')
            ->willReturn([]);

        $this->provider->ping();
        $this->addToAssertionCount(1);
    }

    public function testListAttachmentsReturnsIssueAttachments(): void
    {
        $attachments = [
            new IssueAttachment('1', 'spec.md', 100, 'https://jira.example.com/content/1'),
        ];
        $workItem = new WorkItem(
            '10001',
            'SCI-1',
            'Title',
            'Open',
            'User',
            'Desc',
            [],
            'Task',
            attachments: $attachments,
        );
        $this->jiraApiClient->expects($this->once())
            ->method('getIssue')
            ->with('SCI-1', true)
            ->willReturn($workItem);

        $this->assertSame($attachments, $this->provider->listAttachments('SCI-1'));
    }

    public function testUploadAttachmentDelegatesToAttachmentService(): void
    {
        $this->attachmentService->expects($this->once())
            ->method('uploadFileToIssue')
            ->with('SCI-1', '/tmp/spec.md');

        $this->provider->uploadAttachment('SCI-1', '/tmp/spec.md');
        $this->addToAssertionCount(1);
    }

    public function testDownloadAttachmentWritesContentToDestination(): void
    {
        $dest = sys_get_temp_dir() . '/jira-work-item-provider-' . uniqid('', true) . '.bin';
        $this->attachmentService->expects($this->once())
            ->method('downloadAttachmentContent')
            ->with('https://jira.example.com/content/1')
            ->willReturn('payload');

        try {
            $this->provider->downloadAttachment('https://jira.example.com/content/1', $dest);
            $this->assertSame('payload', file_get_contents($dest));
        } finally {
            if (is_file($dest)) {
                unlink($dest);
            }
        }
    }

    public function testDownloadAttachmentThrowsWhenWriteFails(): void
    {
        $this->attachmentService->expects($this->once())
            ->method('downloadAttachmentContent')
            ->willReturn('payload');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Could not write attachment to destination path.');

        $this->provider->downloadAttachment('https://jira.example.com/content/1', '/dev/null/impossible/path.bin');
    }
}
