<?php

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\Exception\GitException;
use App\Handler\FlattenHandler;
use App\Response\CommandResponse;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;

class FlattenHandlerTest extends CommandTestCase
{
    private FlattenHandler $handler;
    private string $baseBranch = 'origin/develop';

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new FlattenHandler($this->gitRepository, $this->baseBranch, $this->translationService);
    }

    public function testHandleWithDirtyWorkingDirectory(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn("M  file.php\nA  newfile.php");

        $this->gitRepository->expects($this->never())->method('getMergeBase');
        $this->gitRepository->expects($this->never())->method('hasFixupCommits');
        $this->gitRepository->expects($this->never())->method('rebaseAutosquash');

        $result = $this->handler->handle();

        $error = $result->getErrorMessage();
        $this->assertFalse($result->isSuccess());
        $this->assertInstanceOf(MessageRef::class, $error);
        $this->assertSame('flatten.error_dirty_working', $error->key);
    }

    public function testHandleWithNoFixupCommits(): void
    {
        $this->allowCleanTree();
        $this->gitRepository->expects($this->once())->method('getMergeBase')->with($this->baseBranch, 'HEAD')->willReturn('abc123');
        $this->gitRepository->expects($this->once())->method('hasFixupCommits')->with('abc123')->willReturn(false);
        $this->gitRepository->expects($this->never())->method('rebaseAutosquash');

        $result = $this->handler->handle();

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertInstanceOf(MessageRef::class, $result->getNotices()[0]->message);
        $this->assertSame('flatten.no_fixups', $result->getNotices()[0]->message->key);
    }

    public function testStandaloneRewriteKeepsPleaseHint(): void
    {
        $this->expectRewrite();

        $result = $this->handler->handle();

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['rewritten']);
        $this->assertInstanceOf(MessageRef::class, $result->getWarnings()[0]->message);
        $this->assertSame('flatten.warning_rewrite', $result->getWarnings()[0]->message->key);
    }

    public function testDeliveryRewriteOmitsPleaseHint(): void
    {
        $this->expectRewrite();

        $result = $this->handler->handle(false);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['rewritten']);
        $this->assertInstanceOf(MessageRef::class, $result->getWarnings()[0]->message);
        $this->assertSame('flatten.warning_published', $result->getWarnings()[0]->message->key);
    }

    public function testHandleWithRebaseFailure(): void
    {
        $this->allowCleanTree();
        $this->gitRepository->method('getMergeBase')->willReturn('abc123');
        $this->gitRepository->method('hasFixupCommits')->willReturn(true);
        $this->gitRepository->method('rebaseAutosquash')->willThrowException(new \RuntimeException('Rebase failed'));

        $result = $this->handler->handle();

        $this->assertFalse($result->isSuccess());
        $error = $result->getErrorMessage();
        $this->assertFalse($result->data['rewritten']);
        $this->assertInstanceOf(MessageRef::class, $error);
        $this->assertSame('flatten.error_rebase', $error->key);
    }

    public function testHandleWithRebaseFailureGitException(): void
    {
        $this->allowCleanTree();
        $this->gitRepository->method('getMergeBase')->willReturn('abc123');
        $this->gitRepository->method('hasFixupCommits')->willReturn(true);
        $this->gitRepository->method('rebaseAutosquash')->willThrowException(new GitException('Git command failed: git rebase', 'fatal: could not read object', null));

        $result = $this->handler->handle();

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertSame('fatal: could not read object', $result->getErrors()[0]->technicalDetails);
    }

    public function testApplyToCommitResultSkipsWhenNoCommitWasCreated(): void
    {
        $this->gitRepository->expects($this->never())->method('rebaseAutosquash');
        $commit = CommandResponse::success(MessageRef::key('commit.success'));

        $result = $this->handler->applyToCommitResult($commit);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertInstanceOf(MessageRef::class, $result->getNotices()[0]->message);
        $this->assertSame('flatten.notice_skipped', $result->getNotices()[0]->message->key);
    }

    public function testApplyToCommitResultSkipsDirtyTree(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn(' M dirty.txt');
        $this->gitRepository->expects($this->never())->method('rebaseAutosquash');
        $commit = CommandResponse::success(MessageRef::key('commit.success'), ['commitMessage' => 'feat: x']);

        $result = $this->handler->applyToCommitResult($commit);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertSame('feat: x', $result->data['commitMessage']);
        $this->assertInstanceOf(MessageRef::class, $result->getNotices()[0]->message);
        $this->assertSame('flatten.notice_skipped', $result->getNotices()[0]->message->key);
    }

    public function testApplyToCommitResultReportsRewriteOnACleanCreatedCommit(): void
    {
        $this->expectRewrite();
        $commit = CommandResponse::success(MessageRef::key('commit.success'), ['commitMessage' => 'feat: x']);

        $result = $this->handler->applyToCommitResult($commit);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['rewritten']);
        $this->assertSame('feat: x', $result->data['commitMessage']);
        $this->assertInstanceOf(MessageRef::class, $result->getWarnings()[0]->message);
        $this->assertSame('flatten.warning_published', $result->getWarnings()[0]->message->key);
    }

    public function testCommitFlattenKeepsThePleaseHint(): void
    {
        $this->expectRewrite();
        $commit = CommandResponse::success(MessageRef::key('commit.success'), ['commitMessage' => 'feat: x']);

        $result = $this->handler->applyToCommitResult($commit, true);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['rewritten']);
        $this->assertInstanceOf(MessageRef::class, $result->getWarnings()[0]->message);
        $this->assertSame('flatten.warning_rewrite', $result->getWarnings()[0]->message->key);
    }

    public function testApplyToCommitResultKeepsCommitPayloadWhenRebaseFails(): void
    {
        $this->allowCleanTree();
        $this->gitRepository->method('getMergeBase')->willReturn('abc123');
        $this->gitRepository->method('hasFixupCommits')->willReturn(true);
        $this->gitRepository->method('rebaseAutosquash')->willThrowException(new \RuntimeException('Rebase failed'));
        $commit = CommandResponse::success(MessageRef::key('commit.success'), ['commitMessage' => 'feat: x']);

        $result = $this->handler->applyToCommitResult($commit);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('feat: x', $result->data['commitMessage']);
        $this->assertFalse($result->data['rewritten']);
    }

    private function allowCleanTree(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
    }

    private function expectRewrite(): void
    {
        $this->allowCleanTree();
        $this->gitRepository->method('getMergeBase')->willReturn('abc123');
        $this->gitRepository->method('hasFixupCommits')->willReturn(true);
        $this->gitRepository->expects($this->once())->method('rebaseAutosquash')->with('abc123');
    }
}
