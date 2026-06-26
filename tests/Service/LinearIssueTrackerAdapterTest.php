<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\StateChange;
use App\Service\LinearApiClient;
use App\Service\LinearIssueTrackerAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LinearIssueTrackerAdapterTest extends TestCase
{
    private LinearApiClient&MockObject $linearApiClient;

    private LinearIssueTrackerAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->adapter = new LinearIssueTrackerAdapter($this->linearApiClient);
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
        yield 'create' => ['create', [['summary' => 'Title']]];
        yield 'update' => ['update', ['SCI-1', ['summary' => 'Updated']]];
        yield 'getCreateMetaFields' => ['getCreateMetaFields', ['SCI', '10001']];
        yield 'getEditMetaFields' => ['getEditMetaFields', ['SCI-1']];
        yield 'formatDescription' => ['formatDescription', ['text', 'plain']];
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
