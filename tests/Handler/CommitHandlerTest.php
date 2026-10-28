<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\CommitHandler;
use App\Service\Prompt\PromptInterface;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class CommitHandlerTest extends CommandTestCase
{
    private CommitHandler $handler;
    private PromptInterface&MockObject $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prompt = $this->createMock(PromptInterface::class);
        $this->handler = new CommitHandler($this->gitRepository, $this->issueTracker, 'origin/develop', $this->translationService, $this->prompt);
    }

    public function testHandleWithCleanWorkingTree(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->getMessages()[0]->message, 'commit.note_clean_working_tree');
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithCleanWorkingTreeAndWhitespace(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('   ');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->getMessages()[0]->message, 'commit.note_clean_working_tree');
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithMessage(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('my message');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, 'my message', false);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('my message', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithMessageReturnsErrorOnGitTimeoutDuringCommit(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true);

        $this->gitRepository
            ->method('commit')
            ->with('my message')
            ->willThrowException(new \App\Exception\GitTimeoutException(
                'git commit -m my message',
                600.0,
                'timed out',
            ));

        $result = $this->handler->handle(false, 'my message', false, true);

        $this->assertMessageRef($result->getErrorMessage(), 'git.error.timeout');
        $this->assertFalse($result->isSuccess());
        $this->assertCount(1, $result->getMessages());
        $this->assertSame('timed out', $result->getMessages()[0]->technicalDetails);
    }

    public function testHandleWithMessageReturnsErrorOnGitExceptionDuringCommit(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true);

        $this->gitRepository
            ->method('commit')
            ->with('my message')
            ->willThrowException(new \App\Exception\GitException('timeout message', 'technical details'));

        $result = $this->handler->handle(false, 'my message', false, true);

        $this->assertMessageRef($result->getErrorMessage(), 'commit.error_git');
        $this->assertFalse($result->isSuccess());
        $this->assertCount(1, $result->getMessages());
        $this->assertSame('technical details', $result->getMessages()[0]->technicalDetails);
    }

    public function testHandleWithMessageUsingNewSignature(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true);

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository
            ->method('commit')
            ->with('my message');

        $result = $this->handler->handle(false, 'my message', false, false);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('my message', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithMessageAndAllFlag(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository->expects($this->once())
            ->method('stageAllChanges');

        $this->gitRepository
            ->method('commit')
            ->with('my message');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, 'my message', true);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('my message', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithMessageNoStagedChanges(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(false); // No staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->never())
            ->method('commit');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, 'my message', false);

        $this->assertMessageRef($result->getErrorMessage(), 'commit.no_staged_changes');
        $this->assertFalse($result->isSuccess());
    }

    public function testHandleWithAutoFixup(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn('abcdef');

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commitFixup')
            ->with('abcdef');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);



        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->message, 'commit.fixup_success', ['sha' => 'abcdef']);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('abcdef', $result->data['fixupSha'] ?? null);
    }

    public function testHandleWithAutoFixupAndAllFlag(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn('abcdef');

        $this->gitRepository->expects($this->once())
            ->method('stageAllChanges');

        $this->gitRepository
            ->method('commitFixup')
            ->with('abcdef');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);



        $result = $this->handler->handle($io, false, null, true);

        $this->assertMessageRef($result->message, 'commit.fixup_success', ['sha' => 'abcdef']);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('abcdef', $result->data['fixupSha'] ?? null);
    }

    public function testHandleWithAutoFixupReturnsErrorOnGitExceptionDuringCommitFixup(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn('abcdef');

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true);

        $this->gitRepository
            ->method('commitFixup')
            ->with('abcdef')
            ->willThrowException(new \App\Exception\GitTimeoutException('git commit --fixup abcdef', 600.0, 'timed out'));

        $result = $this->handler->handle(false, null, false, true);

        $this->assertMessageRef($result->getErrorMessage(), 'git.error.timeout');
        $this->assertFalse($result->isSuccess());
        $this->assertGreaterThanOrEqual(1, count($result->getMessages()));
    }

    public function testHandleWithAutoFixupNoStagedChanges(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn('abcdef');

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(false); // No staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->never())
            ->method('commitFixup');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);


        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->getErrorMessage(), 'commit.no_staged_changes');
        $this->assertFalse($result->isSuccess());
    }

    public function testHandleWithNewBranchSkipsLogicalShaLookup(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository->expects($this->never())
            ->method('findLatestLogicalSha');

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'New feature',
            status: 'In Progress',
            assignee: 'Jane',
            description: 'Desc',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true);

        $this->gitRepository
            ->method('commit')
            ->with('feat(api): New feature [TPW-35]');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, true, null, false, true);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('feat(api): New feature [TPW-35]', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithInteractivePrompter(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->issueTracker->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->prompt->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    $question->key === 'commit.type_prompt' => 'feat',
                    $question->key === 'commit.scope_auto' => 'api',
                    $question->key === 'commit.summary_prompt' => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );


        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('feat(api): My awesome feature [TPW-35]', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithQuietUsesDetectedTypeScopeSummaryWithoutPrompting(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true);

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->once())
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $this->prompt->expects($this->never())
            ->method('ask');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false, true);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('feat(api): My awesome feature [TPW-35]', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithQuietJiraPromptReturnsErrorOnGitTimeoutDuringCommit(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true);

        $this->gitRepository
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]')
            ->willThrowException(new \App\Exception\GitTimeoutException(
                'git commit -m feat(api): My awesome feature [TPW-35]',
                600.0,
                'timed out',
            ));

        $result = $this->handler->handle(false, null, false, true);

        $this->assertMessageRef($result->getErrorMessage(), 'git.error.timeout');
        $this->assertFalse($result->isSuccess());
        $this->assertGreaterThanOrEqual(2, count($result->getMessages()));
    }

    public function testHandleWithInteractivePrompterAndAllFlag(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository->expects($this->once())
            ->method('stageAllChanges');

        $this->gitRepository
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->prompt->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    $question->key === 'commit.type_prompt' => 'feat',
                    $question->key === 'commit.scope_auto' => 'api',
                    $question->key === 'commit.summary_prompt' => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );


        $result = $this->handler->handle($io, false, null, true);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('feat(api): My awesome feature [TPW-35]', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithInteractivePrompterNoStagedChanges(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(false); // No staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository->expects($this->never())
            ->method('commit');


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->prompt->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    $question->key === 'commit.type_prompt' => 'feat',
                    $question->key === 'commit.scope_auto' => 'api',
                    $question->key === 'commit.summary_prompt' => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );

        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->getErrorMessage(), 'commit.no_staged_changes');
        $this->assertFalse($result->isSuccess());
    }

    public function testHandleWithNoJiraKeyInBranchName(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn(null);


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->getErrorMessage(), 'commit.error_no_key');
        $this->assertFalse($result->isSuccess());
    }

    public function testHandleWithIssueTrackerException(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $this->issueTracker
            ->method('getIssue')
            ->willThrowException(new \Exception('Jira service error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Jira service error');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->handler->handle($io, false, null, false);
    }

    public function testHandleWithJiraApiClientApiException(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $this->issueTracker
            ->method('getIssue')
            ->willThrowException(new \App\Exception\ApiException('Could not find Jira issue with key "TPW-35".', 'HTTP 404: Not Found', 404));


        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->getErrorMessage(), 'commit.error_not_found', ['key' => 'TPW-35']);
        $this->assertFalse($result->isSuccess());
    }

    public function testHandleWithVeryVerboseOutput(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->prompt->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    $question->key === 'commit.type_prompt' => 'feat',
                    $question->key === 'commit.scope_auto' => 'api',
                    $question->key === 'commit.summary_prompt' => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );


        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('feat(api): My awesome feature [TPW-35]', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithEmptyComponents(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: [],
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository
            ->method('commit')
            ->with('feat: My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->prompt->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    $question->key === 'commit.type_prompt' => 'feat',
                    $question->key === 'commit.scope_prompt' => '',
                    $question->key === 'commit.summary_prompt' => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );


        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('feat: My awesome feature [TPW-35]', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testHandleWithVerboseOutputForCommitMessage(): void
    {
        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository
            ->method('commit')
            ->with('feat(api): My awesome feature [TPW-35]');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->prompt->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    $question->key === 'commit.type_prompt' => 'feat',
                    $question->key === 'commit.scope_auto' => 'api',
                    $question->key === 'commit.summary_prompt' => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );


        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('feat(api): My awesome feature [TPW-35]', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testgetCommitTypeFromIssueTypeWithUnknownType(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->gitRepository
            ->method('getPorcelainStatus')
            ->willReturn('M  file.txt');

        $this->gitRepository
            ->method('findLatestLogicalSha')
            ->willReturn(null);

        $this->gitRepository
            ->method('getIssueKeyFromBranchName')
            ->willReturn('TPW-35');

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'My awesome feature',
            status: 'In Progress',
            assignee: 'John Doe',
            description: 'A description',
            labels: [],
            issueType: 'unknown',
            components: [],
        );

        $this->issueTracker
            ->method('getIssue')
            ->willReturn($workItem);

        $this->gitRepository
            ->method('hasStagedChanges')
            ->willReturn(true); // Has staged changes

        $this->gitRepository->expects($this->never())
            ->method('stageAllChanges');

        $this->gitRepository
            ->method('commit')
            ->with('feat: My awesome feature [TPW-35]');

        $this->prompt->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(
                fn ($question, ?string $default) => match (true) {
                    $question->key === 'commit.type_prompt' => 'feat',
                    $question->key === 'commit.scope_prompt' => '',
                    $question->key === 'commit.summary_prompt' => 'My awesome feature',
                    default => throw new \RuntimeException('Unexpected question: ' . $question),
                }
            );


        $result = $this->handler->handle($io, false, null, false);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('feat: My awesome feature [TPW-35]', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
    }

    public function testgetCommitTypeFromIssueType(): void
    {
        $prompt = $this->createStub(PromptInterface::class);
        $handler = new CommitHandler($this->gitRepository, $this->issueTracker, 'origin/develop', $this->translationService, $prompt);

        $this->assertSame('fix', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['bug']));
        $this->assertSame('feat', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['story']));
        $this->assertSame('feat', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['epic']));
        $this->assertSame('chore', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['task']));
        $this->assertSame('chore', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['sub-task']));
        $this->assertSame('feat', $this->callPrivateMethod($handler, 'getCommitTypeFromIssueType', ['unknown']));
    }

    public function testExplicitMessageDoesNotInvokeIssueTrackerFactory(): void
    {
        $invoked = false;
        $factory = function () use (&$invoked): \App\Service\IssueTrackerPort {
            $invoked = true;

            return $this->issueTracker;
        };
        $handler = new CommitHandler($this->gitRepository, $factory, 'origin/develop', $this->translationService, $this->prompt);

        $this->gitRepository->method('getPorcelainStatus')->willReturn('M  file.txt');
        $this->gitRepository->method('hasStagedChanges')->willReturn(true);
        $this->gitRepository->expects($this->once())->method('commit')->with('my message');

        $result = $handler->handle(false, 'my message', false, true);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('my message', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($invoked);
    }

    public function testFixupPathDoesNotInvokeIssueTrackerFactory(): void
    {
        $invoked = false;
        $factory = function () use (&$invoked): \App\Service\IssueTrackerPort {
            $invoked = true;

            return $this->issueTracker;
        };
        $handler = new CommitHandler($this->gitRepository, $factory, 'origin/develop', $this->translationService, $this->prompt);

        $this->gitRepository->method('getPorcelainStatus')->willReturn('M  file.txt');
        $this->gitRepository->method('findLatestLogicalSha')->willReturn('abcdef');
        $this->gitRepository->method('hasStagedChanges')->willReturn(true);
        $this->gitRepository->expects($this->once())->method('commitFixup')->with('abcdef');

        $result = $handler->handle(false, null, false, true);

        $this->assertMessageRef($result->message, 'commit.fixup_success', ['sha' => 'abcdef']);
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($invoked);
    }

    public function testJiraPromptPathInvokesIssueTrackerFactoryOnce(): void
    {
        $invoked = 0;
        $factory = function () use (&$invoked): \App\Service\IssueTrackerPort {
            ++$invoked;

            return $this->issueTracker;
        };
        $handler = new CommitHandler($this->gitRepository, $factory, 'origin/develop', $this->translationService, $this->prompt);

        $this->gitRepository->method('getPorcelainStatus')->willReturn('M  file.txt');
        $this->gitRepository->method('findLatestLogicalSha')->willReturn(null);
        $this->gitRepository->method('getIssueKeyFromBranchName')->willReturn('TPW-35');
        $this->gitRepository->method('hasStagedChanges')->willReturn(true);

        $workItem = new WorkItem(
            id: '10001',
            key: 'TPW-35',
            title: 'New feature',
            status: 'In Progress',
            assignee: 'Jane',
            description: 'Desc',
            labels: [],
            issueType: 'story',
            components: ['api'],
        );
        $this->issueTracker->expects($this->once())->method('getIssue')->with('TPW-35')->willReturn($workItem);
        $this->gitRepository->expects($this->once())->method('commit')->with('feat(api): New feature [TPW-35]');

        $result = $handler->handle(false, null, false, true);

        $this->assertMessageRef($result->message, 'commit.success');
        $this->assertSame('feat(api): New feature [TPW-35]', $result->data['commitMessage']);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $invoked);
    }
}
