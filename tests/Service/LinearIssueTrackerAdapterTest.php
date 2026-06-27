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

    public function testListLabelGroupsDelegatesToLinearApiClient(): void
    {
        $groups = [['id' => 'g1', 'name' => 'Type', 'labels' => []]];
        $this->linearApiClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', true)
            ->willReturn($groups);

        $this->assertSame($groups, $this->adapter->listLabelGroups('SCI', true));
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
        yield 'getIssue' => ['getIssue', ['SCI-1', false]];
        yield 'search' => ['search', ['project = SCI']];
        yield 'listAssignedActive' => ['listAssignedActive', ['SCI', true]];
        yield 'listItemStateChanges' => ['listItemStateChanges', ['SCI-1']];
        yield 'applyStateChange' => ['applyStateChange', ['SCI-1', '11']];
        yield 'assign' => ['assign', ['SCI-1', 'user@example.com']];
        yield 'listTeams' => ['listTeams', []];
        yield 'listFiltersOrViews' => ['listFiltersOrViews', []];
        yield 'runFilterOrView' => ['runFilterOrView', ['My View']];
        yield 'listWorkflowMetadata' => ['listWorkflowMetadata', ['SCI']];
        yield 'listTypeLabels' => ['listTypeLabels', ['SCI']];
        yield 'ping' => ['ping', []];
        yield 'listAttachments' => ['listAttachments', ['SCI-1']];
        yield 'uploadAttachment' => ['uploadAttachment', ['SCI-1', '/tmp/file.txt']];
        yield 'downloadAttachment' => ['downloadAttachment', ['https://linear.app/file', '/tmp/file.txt']];
    }
}
