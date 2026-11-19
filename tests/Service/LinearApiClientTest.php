<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\Project;
use App\Service\Linear\LinearIssueQueryClient;
use App\Service\Linear\LinearMutationClient;
use App\Service\Linear\LinearTeamMetadataClient;
use App\Service\Linear\LinearWorkspaceClient;
use App\Service\LinearApiClient;
use PHPUnit\Framework\TestCase;

class LinearApiClientTest extends TestCase
{
    private function createService(
        ?LinearTeamMetadataClient $teamMetadataClient = null,
        ?LinearWorkspaceClient $workspaceClient = null,
        ?LinearIssueQueryClient $issueQueryClient = null,
        ?LinearMutationClient $mutationClient = null,
    ): LinearApiClient {
        return new LinearApiClient(
            $teamMetadataClient ?? $this->createStub(LinearTeamMetadataClient::class),
            $workspaceClient ?? $this->createStub(LinearWorkspaceClient::class),
            $issueQueryClient ?? $this->createStub(LinearIssueQueryClient::class),
            $mutationClient ?? $this->createStub(LinearMutationClient::class),
        );
    }

    public function testTeamMetadataMethodsDelegateToTeamMetadataClient(): void
    {
        $states = [['id' => 'state-1', 'name' => 'Todo', 'type' => 'unstarted']];
        $groups = [['id' => 'group-1', 'name' => 'Type', 'labels' => []]];
        $teamMetadataClient = $this->createMock(LinearTeamMetadataClient::class);
        $teamMetadataClient->expects($this->once())->method('getTeamWorkflowStates')->with('SCI')->willReturn($states);
        $teamMetadataClient->expects($this->once())->method('getTeamLabelGroups')->with('SCI', true)->willReturn($groups);
        $teamMetadataClient->expects($this->once())->method('resolveLabelIds')->with('SCI', ['Bug'], 'group-1')->willReturn(['label-1']);

        $service = $this->createService(teamMetadataClient: $teamMetadataClient);

        $this->assertSame($states, $service->getTeamWorkflowStates('SCI'));
        $this->assertSame($groups, $service->getTeamLabelGroups('SCI', true));
        $this->assertSame(['label-1'], $service->resolveLabelIds('SCI', ['Bug'], 'group-1'));
    }

    public function testWorkspaceMethodsDelegateToWorkspaceClient(): void
    {
        $project = new Project(key: 'SCI', name: 'Stud');
        $view = ['id' => 'view-1', 'name' => 'Bugs', 'description' => null, 'filterData' => []];
        $workspaceClient = $this->createMock(LinearWorkspaceClient::class);
        $workspaceClient->expects($this->once())->method('getTeamByKey')->with('SCI')->willReturn($project);
        $workspaceClient->expects($this->once())->method('resolveTeamId')->with('SCI')->willReturn('team-uuid');
        $workspaceClient->expects($this->once())->method('listTeams')->willReturn([['key' => 'SCI', 'name' => 'Stud']]);
        $workspaceClient->expects($this->once())->method('listCustomViews')->willReturn([$view]);
        $workspaceClient->expects($this->once())->method('resolveCustomViewByName')->with('Bugs')->willReturn($view);

        $service = $this->createService(workspaceClient: $workspaceClient);

        $this->assertSame($project, $service->getTeamByKey('SCI'));
        $this->assertSame('team-uuid', $service->resolveTeamId('SCI'));
        $this->assertSame([['key' => 'SCI', 'name' => 'Stud']], $service->listTeams());
        $this->assertSame([$view], $service->listCustomViews());
        $this->assertSame($view, $service->resolveCustomViewByName('Bugs'));
    }

    public function testPingAndGetViewerIdDelegateToWorkspaceClient(): void
    {
        $workspaceClient = $this->createMock(LinearWorkspaceClient::class);
        $workspaceClient->expects($this->exactly(2))->method('getViewerId')->willReturn('viewer-1');

        $service = $this->createService(workspaceClient: $workspaceClient);
        $service->ping();

        $this->assertSame('viewer-1', $service->getViewerId());
    }

    public function testIssueQueryMethodsDelegateToIssueQueryClient(): void
    {
        $issue = ['identifier' => 'SCI-1'];
        $filter = ['number' => ['eq' => 1]];
        $issueQueryClient = $this->createMock(LinearIssueQueryClient::class);
        $issueQueryClient->expects($this->once())->method('resolveIssueId')->with('SCI-1')->willReturn('uuid-1');
        $issueQueryClient->expects($this->once())->method('resolveTeamKeyFromIssue')->with('SCI-1')->willReturn('SCI');
        $issueQueryClient->expects($this->once())->method('getIssue')->with('SCI-1')->willReturn($issue);
        $issueQueryClient->expects($this->once())->method('listAssignedActiveIssues')->with('SCI', true)->willReturn([$issue]);
        $issueQueryClient->expects($this->once())->method('listIssuesByFilter')->with($filter)->willReturn([$issue]);
        $issueQueryClient->expects($this->once())->method('searchIssues')->with('bug', 25, 'cursor')->willReturn([$issue]);

        $service = $this->createService(issueQueryClient: $issueQueryClient);

        $this->assertSame('uuid-1', $service->resolveIssueId('SCI-1'));
        $this->assertSame('SCI', $service->resolveTeamKeyFromIssue('SCI-1'));
        $this->assertSame($issue, $service->getIssue('SCI-1'));
        $this->assertSame([$issue], $service->listAssignedActiveIssues('SCI', true));
        $this->assertSame([$issue], $service->listIssuesByFilter($filter));
        $this->assertSame([$issue], $service->searchIssues('bug', 25, 'cursor'));
    }

    public function testMutationMethodsDelegateToMutationClient(): void
    {
        $input = ['teamId' => 't1', 'title' => 'New', 'description' => null, 'labelIds' => [], 'priority' => null, 'parentId' => null];
        $created = ['identifier' => 'SCI-99', 'url' => 'https://linear.app/SCI-99'];
        $upload = ['uploadUrl' => 'https://u', 'assetUrl' => 'https://a', 'headers' => []];
        $mutationClient = $this->createMock(LinearMutationClient::class);
        $mutationClient->expects($this->once())->method('issueCreate')->with($input)->willReturn($created);
        $mutationClient->expects($this->once())->method('issueUpdate')->with('issue-1', ['title' => 'T']);
        $mutationClient->expects($this->once())->method('fileUpload')->with('x.md', 'text/markdown', 3)->willReturn($upload);
        $mutationClient->expects($this->once())->method('attachmentCreate')->with('issue-1', 'x.md', 'https://a');

        $service = $this->createService(mutationClient: $mutationClient);

        $this->assertSame($created, $service->issueCreate($input));
        $service->issueUpdate('issue-1', ['title' => 'T']);
        $this->assertSame($upload, $service->fileUpload('x.md', 'text/markdown', 3));
        $service->attachmentCreate('issue-1', 'x.md', 'https://a');
    }

    public function testAssignIssueUsesViewerWhenAssigneeOmitted(): void
    {
        $issueQueryClient = $this->createStub(LinearIssueQueryClient::class);
        $issueQueryClient->method('resolveIssueId')->willReturn('issue-uuid-1');
        $workspaceClient = $this->createMock(LinearWorkspaceClient::class);
        $workspaceClient->expects($this->once())->method('getViewerId')->willReturn('viewer-uuid-1');
        $mutationClient = $this->createMock(LinearMutationClient::class);
        $mutationClient->expects($this->once())
            ->method('issueUpdate')
            ->with('issue-uuid-1', ['assigneeId' => 'viewer-uuid-1']);

        $this->createService(null, $workspaceClient, $issueQueryClient, $mutationClient)->assignIssue('SCI-1');
    }

    public function testAssignIssueUsesProvidedAssigneeId(): void
    {
        $issueQueryClient = $this->createStub(LinearIssueQueryClient::class);
        $issueQueryClient->method('resolveIssueId')->willReturn('issue-uuid-1');
        $workspaceClient = $this->createMock(LinearWorkspaceClient::class);
        $workspaceClient->expects($this->never())->method('getViewerId');
        $mutationClient = $this->createMock(LinearMutationClient::class);
        $mutationClient->expects($this->once())
            ->method('issueUpdate')
            ->with('issue-uuid-1', ['assigneeId' => 'other-user-uuid']);

        $this->createService(null, $workspaceClient, $issueQueryClient, $mutationClient)->assignIssue('SCI-1', 'other-user-uuid');
    }
}
