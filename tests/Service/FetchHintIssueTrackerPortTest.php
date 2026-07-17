<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\WorkItem;
use App\Enum\IssueTrackerProvider;
use App\Exception\ApiException;
use App\Service\FetchHintIssueTrackerPort;
use App\Service\IssueTrackerFetchFailureHintBuilder;
use App\Service\IssueTrackerPort;
use PHPUnit\Framework\TestCase;

class FetchHintIssueTrackerPortTest extends TestCase
{
    public function testGetIssueAttachesResolutionHintOnFailure(): void
    {
        $inner = $this->createMock(IssueTrackerPort::class);
        $inner->expects($this->once())
            ->method('getIssue')
            ->with('SCIL-195', false)
            ->willThrowException(new ApiException('not found', 'HTTP 404', 404));

        $port = new FetchHintIssueTrackerPort(
            $inner,
            new IssueTrackerFetchFailureHintBuilder(),
            IssueTrackerProvider::Jira->value,
            null,
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin-key',
            ],
            [
                'issueTrackerProvider' => IssueTrackerProvider::Jira->value,
                'projectKey' => 'SCI',
                'linearTeamKey' => 'SCIL',
            ],
        );

        try {
            $port->getIssue('SCIL-195');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $hint = $e->getResolutionHint();
            $this->assertNotNull($hint);
            $this->assertSame('issue_tracker_provider.fetch_failed_prefix_matches_other', $hint->key);
        }
    }

    public function testGetIssuePassesThroughSuccess(): void
    {
        $issue = new WorkItem('1', 'SCI-1', 'Title', 'Open', 'Ada', 'Desc', [], 'Task');
        $inner = $this->createMock(IssueTrackerPort::class);
        $inner->expects($this->once())
            ->method('getIssue')
            ->willReturn($issue);

        $port = new FetchHintIssueTrackerPort(
            $inner,
            new IssueTrackerFetchFailureHintBuilder(),
            IssueTrackerProvider::Jira->value,
            null,
            [],
            ['issueTrackerProvider' => IssueTrackerProvider::Jira->value, 'projectKey' => 'SCI'],
        );

        $this->assertSame($issue, $port->getIssue('SCI-1'));
    }

    public function testDelegatesRemainingPortMethods(): void
    {
        $inner = $this->createMock(IssueTrackerPort::class);
        $inner->expects($this->once())->method('search')->with('q')->willReturn([]);
        $inner->expects($this->once())->method('listAssignedActive')->with('SCI', true)->willReturn([]);
        $inner->expects($this->once())->method('create')->with(['title' => 't'])->willReturn(['key' => 'SCI-1', 'self' => 'u']);
        $inner->expects($this->once())->method('update')->with('SCI-1', ['title' => 'n']);
        $inner->expects($this->once())->method('getCreateMetaFields')->with('SCI', '1')->willReturn([]);
        $inner->expects($this->once())->method('getEditMetaFields')->with('SCI-1')->willReturn([]);
        $inner->expects($this->once())->method('formatDescription')->with('x', 'plain')->willReturn(['type' => 'doc', 'version' => 1, 'content' => []]);
        $inner->expects($this->once())->method('listProjectStateChanges')->with('SCI')->willReturn([]);
        $inner->expects($this->once())->method('listItemStateChanges')->with('SCI-1')->willReturn([]);
        $inner->expects($this->once())->method('applyStateChange')->with('SCI-1', '2');
        $inner->expects($this->once())->method('assign')->with('SCI-1', null);
        $inner->expects($this->once())->method('listTeams')->willReturn([]);
        $inner->expects($this->once())->method('listFiltersOrViews')->willReturn([]);
        $inner->expects($this->once())->method('runFilterOrView')->with('f')->willReturn([]);
        $inner->expects($this->once())->method('ping');
        $inner->expects($this->once())->method('listAttachments')->with('SCI-1')->willReturn([]);
        $inner->expects($this->once())->method('uploadAttachment')->with('SCI-1', '/tmp/a');
        $inner->expects($this->once())->method('downloadAttachment')->with('http://x', '/tmp/b');

        $port = new FetchHintIssueTrackerPort(
            $inner,
            new IssueTrackerFetchFailureHintBuilder(),
            IssueTrackerProvider::Jira->value,
            null,
            [],
            [],
        );

        $this->assertSame([], $port->search('q'));
        $this->assertSame([], $port->listAssignedActive('SCI', true));
        $this->assertSame(['key' => 'SCI-1', 'self' => 'u'], $port->create(['title' => 't']));
        $port->update('SCI-1', ['title' => 'n']);
        $this->assertSame([], $port->getCreateMetaFields('SCI', '1'));
        $this->assertSame([], $port->getEditMetaFields('SCI-1'));
        $this->assertSame(['type' => 'doc', 'version' => 1, 'content' => []], $port->formatDescription('x'));
        $this->assertSame([], $port->listProjectStateChanges('SCI'));
        $this->assertSame([], $port->listItemStateChanges('SCI-1'));
        $port->applyStateChange('SCI-1', '2');
        $port->assign('SCI-1');
        $this->assertSame([], $port->listTeams());
        $this->assertSame([], $port->listFiltersOrViews());
        $this->assertSame([], $port->runFilterOrView('f'));
        $port->ping();
        $this->assertSame([], $port->listAttachments('SCI-1'));
        $port->uploadAttachment('SCI-1', '/tmp/a');
        $port->downloadAttachment('http://x', '/tmp/b');
    }
}
