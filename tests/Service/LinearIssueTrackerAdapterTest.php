<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\StateChange;
use App\Service\GitRepository;
use App\Service\LinearApiClient;
use App\Service\LinearIssueTrackerAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LinearIssueTrackerAdapterTest extends TestCase
{
    private LinearApiClient&MockObject $linearApiClient;

    private GitRepository&MockObject $gitRepository;

    private LinearIssueTrackerAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->adapter = new LinearIssueTrackerAdapter($this->linearApiClient, gitRepository: $this->gitRepository);
    }

    public function testCreateDelegatesToLinearApiClient(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['linearTypeLabelGroupId' => 'group-1']);

        $this->linearApiClient->expects($this->once())
            ->method('resolveTeamId')
            ->with('SCI')
            ->willReturn('team-uuid');
        $this->linearApiClient->expects($this->exactly(2))
            ->method('resolveLabelIds')
            ->willReturnOnConsecutiveCalls(['label-dx'], ['label-story']);
        $this->linearApiClient->expects($this->once())
            ->method('resolveIssueId')
            ->with('SCI-1')
            ->willReturn('parent-uuid');
        $this->linearApiClient->expects($this->once())
            ->method('issueCreate')
            ->with($this->callback(function (array $input): bool {
                return $input['teamId'] === 'team-uuid'
                    && $input['title'] === 'New issue'
                    && $input['description'] === '## Body'
                    && $input['priority'] === 2
                    && $input['parentId'] === 'parent-uuid';
            }))
            ->willReturn(['identifier' => 'SCI-42', 'url' => 'https://linear.app/SCI-42']);

        $result = $this->adapter->create([
            'project' => ['key' => 'SCI'],
            'issuetype' => ['name' => 'Story'],
            'summary' => 'New issue',
            'description' => [
                'type' => 'doc',
                'version' => 1,
                'content' => [['type' => 'linearMarkdown', 'markdown' => '## Body']],
            ],
            'labels' => ['DX'],
            'priority' => ['name' => 'High'],
            'parent' => ['key' => 'SCI-1'],
        ]);

        $this->assertSame(['key' => 'SCI-42', 'self' => 'https://linear.app/SCI-42'], $result);
    }

    public function testUpdateDelegatesToLinearApiClient(): void
    {
        $this->linearApiClient->expects($this->once())
            ->method('resolveIssueId')
            ->with('SCI-42')
            ->willReturn('issue-uuid');
        $this->linearApiClient->expects($this->once())
            ->method('resolveTeamKeyFromIssue')
            ->with('SCI-42')
            ->willReturn('SCI');
        $this->linearApiClient->expects($this->once())
            ->method('resolveLabelIds')
            ->with('SCI', ['bug'], null)
            ->willReturn(['label-bug']);
        $this->linearApiClient->expects($this->once())
            ->method('issueUpdate')
            ->with('issue-uuid', $this->callback(function (array $input): bool {
                return $input['title'] === 'Updated'
                    && $input['description'] === 'New body'
                    && $input['labelIds'] === ['label-bug'];
            }));

        $this->adapter->update('SCI-42', [
            'summary' => 'Updated',
            'description' => [
                'type' => 'doc',
                'version' => 1,
                'content' => [['type' => 'linearMarkdown', 'markdown' => 'New body']],
            ],
            'labels' => ['bug'],
        ]);
    }

    public function testGetCreateMetaFieldsReturnsLinearFieldMeta(): void
    {
        $meta = $this->adapter->getCreateMetaFields('SCI', 'Story');

        $this->assertArrayHasKey('labels', $meta);
        $this->assertArrayHasKey('priority', $meta);
    }

    public function testFormatDescriptionReturnsMarkdownPayload(): void
    {
        $payload = $this->adapter->formatDescription('## Spec', 'markdown');

        $this->assertSame('linearMarkdown', $payload['content'][0]['type']);
        $this->assertSame('## Spec', $payload['content'][0]['markdown']);
    }

    public function testGetEditMetaFieldsReturnsLinearFieldMeta(): void
    {
        $meta = $this->adapter->getEditMetaFields('SCI-1');

        $this->assertArrayHasKey('labels', $meta);
        $this->assertArrayHasKey('priority', $meta);
    }

    public function testCreateWithoutGitRepositoryUsesNullTypeGroup(): void
    {
        $adapter = new LinearIssueTrackerAdapter($this->linearApiClient);
        $this->linearApiClient->expects($this->once())->method('resolveTeamId')->willReturn('team-uuid');
        $this->linearApiClient->expects($this->exactly(2))->method('resolveLabelIds')->willReturnOnConsecutiveCalls([], []);
        $this->linearApiClient->expects($this->once())->method('issueCreate')->willReturn([
            'identifier' => 'SCI-7',
            'url' => 'https://linear.app/SCI-7',
        ]);

        $result = $adapter->create([
            'project' => ['key' => 'SCI'],
            'issuetype' => ['name' => 'Task'],
            'summary' => 'No config repo',
        ]);

        $this->assertSame('SCI-7', $result['key']);
    }

    public function testUpdatePassesTypeGroupFromProjectConfig(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['linearTypeLabelGroupId' => 'type-group']);

        $this->linearApiClient->expects($this->once())->method('resolveIssueId')->willReturn('issue-uuid');
        $this->linearApiClient->expects($this->once())->method('resolveTeamKeyFromIssue')->willReturn('SCI');
        $this->linearApiClient->expects($this->once())
            ->method('resolveLabelIds')
            ->with('SCI', ['Story'], 'type-group')
            ->willReturn(['label-story']);
        $this->linearApiClient->expects($this->once())->method('issueUpdate');

        $this->adapter->update('SCI-42', [
            'labels' => ['Story'],
        ]);
    }

    public function testListProjectStateChangesDelegatesToLinearApiClient(): void
    {
        $this->linearApiClient->expects($this->once())
            ->method('getTeamWorkflowStates')
            ->with('SCI')
            ->willReturn([
                ['id' => 's1', 'name' => 'Todo', 'type' => 'unstarted'],
            ]);

        $this->assertEquals(
            [new StateChange('s1', 'Todo', null, 'unstarted')],
            $this->adapter->listProjectStateChanges('SCI'),
        );
    }

    public function testListItemStateChangesResolvesTeamAndMapsWorkflowStates(): void
    {
        $this->linearApiClient->expects($this->once())
            ->method('resolveTeamKeyFromIssue')
            ->with('SCI-123')
            ->willReturn('SCI');
        $this->linearApiClient->expects($this->once())
            ->method('getTeamWorkflowStates')
            ->with('SCI')
            ->willReturn([
                ['id' => 'state-started-uuid', 'name' => 'In Progress', 'type' => 'started'],
            ]);

        $this->assertEquals(
            [new StateChange('state-started-uuid', 'In Progress', null, 'started')],
            $this->adapter->listItemStateChanges('SCI-123'),
        );
    }

    public function testApplyStateChangeDelegatesIssueUpdateWithStateId(): void
    {
        $this->linearApiClient->expects($this->once())
            ->method('resolveIssueId')
            ->with('SCI-123')
            ->willReturn('issue-uuid-1');
        $this->linearApiClient->expects($this->once())
            ->method('issueUpdate')
            ->with('issue-uuid-1', ['stateId' => 'state-started-uuid']);

        $this->adapter->applyStateChange('SCI-123', 'state-started-uuid');
    }

    public function testListLabelGroupsDelegatesToLinearApiClient(): void
    {
        $groups = [['id' => 'g1', 'name' => 'Type', 'labels' => []]];
        $this->linearApiClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', true)
            ->willReturn($groups);

        $this->assertSame($groups, $this->adapter->listLabelGroups('SCI', true));
    }

    public function testGetIssueMapsWorkItemFromLinearApiClient(): void
    {
        $this->gitRepository->method('readProjectConfig')->willReturn(['linearTypeLabelGroupId' => 'type-group']);
        $this->linearApiClient->expects($this->once())
            ->method('getIssue')
            ->with('SCI-1')
            ->willReturn([
                'id' => 'issue-1',
                'identifier' => 'SCI-1',
                'title' => 'Issue',
                'state' => ['name' => 'Todo'],
                'assignee' => ['name' => 'Ada'],
                'description' => 'Body',
                'labels' => ['nodes' => []],
                'attachments' => ['nodes' => []],
            ]);

        $issue = $this->adapter->getIssue('SCI-1');

        $this->assertSame('SCI-1', $issue->key);
        $this->assertSame('Issue', $issue->title);
    }

    public function testListAssignedActiveMapsIssues(): void
    {
        $this->linearApiClient->expects($this->once())
            ->method('listAssignedActiveIssues')
            ->with('ENG', true)
            ->willReturn([
                [
                    'id' => 'i1',
                    'identifier' => 'ENG-1',
                    'title' => 'One',
                    'state' => ['name' => 'Todo'],
                    'assignee' => ['name' => 'Ada'],
                    'labels' => ['nodes' => []],
                ],
            ]);

        $issues = $this->adapter->listAssignedActive('ENG', true);

        $this->assertCount(1, $issues);
        $this->assertSame('ENG-1', $issues[0]->key);
    }

    public function testListTeamsMapsProjects(): void
    {
        $this->linearApiClient->expects($this->once())
            ->method('listTeams')
            ->willReturn([['key' => 'ENG', 'name' => 'Engineering']]);

        $teams = $this->adapter->listTeams();

        $this->assertCount(1, $teams);
        $this->assertSame('ENG', $teams[0]->key);
    }

    public function testPingDelegatesToLinearApiClient(): void
    {
        $this->linearApiClient->expects($this->once())->method('ping');
        $this->adapter->ping();
    }

    public function testAssignDelegatesToLinearApiClient(): void
    {
        $this->linearApiClient->expects($this->once())
            ->method('assignIssue')
            ->with('SCI-1', null);

        $this->adapter->assign('SCI-1');
    }

    public function testAssignPassesExplicitUserId(): void
    {
        $this->linearApiClient->expects($this->once())
            ->method('assignIssue')
            ->with('SCI-1', 'user-uuid-2');

        $this->adapter->assign('SCI-1', 'user-uuid-2');
    }

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('unimplementedMethodProvider')]
    public function testUnimplementedMethodsThrowBadMethodCallException(string $method, array $args): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Not implemented until SCI-164');

        $this->adapter->{$method}(...$args);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<mixed>}>
     */
    public static function unimplementedMethodProvider(): iterable
    {
        yield 'search' => ['search', ['project = SCI']];
        yield 'listFiltersOrViews' => ['listFiltersOrViews', []];
        yield 'runFilterOrView' => ['runFilterOrView', ['My View']];
        yield 'listWorkflowMetadata' => ['listWorkflowMetadata', ['SCI']];
        yield 'listTypeLabels' => ['listTypeLabels', ['SCI']];
        yield 'listAttachments' => ['listAttachments', ['SCI-1']];
        yield 'uploadAttachment' => ['uploadAttachment', ['SCI-1', '/tmp/file.txt']];
        yield 'downloadAttachment' => ['downloadAttachment', ['https://linear.app/file', '/tmp/file.txt']];
    }
}
