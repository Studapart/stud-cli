<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\Exception\GitException;
use App\Handler\FlattenHandler;
use App\Response\CommandResponse;
use App\Tests\CommandTestCase;

class FlattenHandlerTest extends CommandTestCase
{
    private FlattenHandler $handler;

    private string $baseBranch = 'origin/develop';

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new FlattenHandler($this->gitRepository, $this->baseBranch, $this->translationService);
    }

    public function testHandleWithDirtyWorkingDirectory(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn("M  file.php\nA  newfile.php");
        $this->gitRepository->expects($this->never())->method('getMergeBase');
        $this->gitRepository->expects($this->never())->method('rebaseAutosquash');

        $result = $this->handler->handle();

        $this->assertFalse($result->isSuccess());
        $this->assertSame('flatten.error_dirty_working', $this->messageKey($result->getErrorMessage()));
    }

    public function testHandleWithNoFixupCommitsReportsNotRewritten(): void
    {
        $this->expectCleanTree('abc123', false);

        $result = $this->handler->handle();

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertSame('flatten.no_fixups', $this->messageKey($result->getNotices()[0]->message));
    }

    public function testHandleWithFixupCommitsRewritesAndKeepsPleaseHint(): void
    {
        $this->expectCleanTree('abc123', true);
        $this->gitRepository->expects($this->once())->method('rebaseAutosquash')->with('abc123');

        $result = $this->handler->handle();

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['rewritten']);
        $this->assertSame('flatten.warning_rewrite', $this->messageKey($result->getWarnings()[0]->message));
    }

    public function testHandleCanOmitPleaseHint(): void
    {
        $this->expectCleanTree('abc123', true);
        $this->gitRepository->expects($this->once())->method('rebaseAutosquash')->with('abc123');

        $result = $this->handler->handle(false);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['rewritten']);
        $this->assertSame([], $result->getWarnings());
    }

    public function testHandleWithRebaseFailure(): void
    {
        $this->expectCleanTree('abc123', true);
        $this->gitRepository->method('rebaseAutosquash')->willThrowException(new \RuntimeException('Rebase failed'));

        $result = $this->handler->handle();

        $this->assertFalse($result->isSuccess());
        $this->assertSame('flatten.error_rebase', $this->messageKey($result->getErrorMessage()));
        $this->assertFalse($result->data['rewritten']);
    }

    public function testHandleWithRebaseFailureGitExceptionKeepsTechnicalDetails(): void
    {
        $this->expectCleanTree('abc123', true);
        $this->gitRepository->method('rebaseAutosquash')->willThrowException(
            new GitException('Git command failed: git rebase', 'fatal: could not read object', null),
        );

        $result = $this->handler->handle();

        $this->assertFalse($result->isSuccess());
        $this->assertSame('fatal: could not read object', $result->getErrors()[0]->technicalDetails);
        $this->assertFalse($result->data['rewritten']);
    }

    public function testApplyWhenSafeSkipsDirtyTreeWithoutRebase(): void
    {
        $this->gitRepository->expects($this->once())->method('getPorcelainStatus')->willReturn(' M dirty.php');
        $this->gitRepository->expects($this->never())->method('rebaseAutosquash');
        $host = CommandResponse::success(MessageRef::key('commit.success'), ['commitMessage' => 'feat: add [SCI-216]']);

        $result = $this->handler->applyWhenSafe($host, true);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertSame('feat: add [SCI-216]', $result->data['commitMessage']);
        $this->assertSame('flatten.notice_skipped', $this->messageKey($result->getNotices()[0]->message));
    }

    public function testApplyWhenSafeSkipsWhenNoCommitWasCreated(): void
    {
        $this->gitRepository->expects($this->once())->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->expects($this->never())->method('hasFixupCommits');
        $host = CommandResponse::success();

        $result = $this->handler->applyWhenSafe($host, true);

        $this->assertFalse($result->data['rewritten']);
        $this->assertSame('flatten.notice_skipped', $this->messageKey($result->getNotices()[0]->message));
    }

    public function testApplyWhenSafeReturnsHostFailureUnchanged(): void
    {
        $this->gitRepository->expects($this->never())->method('getPorcelainStatus');
        $host = CommandResponse::error(MessageRef::key('commit.error'));

        $result = $this->handler->applyWhenSafe($host, true);

        $this->assertSame($host, $result);
    }

    public function testApplyWhenSafeMergesRebaseFailureIntoCommitPayload(): void
    {
        $this->expectCleanTree('abc123', true);
        $this->gitRepository->method('rebaseAutosquash')->willThrowException(new \RuntimeException('Rebase failed'));
        $host = CommandResponse::success(MessageRef::key('commit.success'), ['fixupSha' => 'abc']);

        $result = $this->handler->applyWhenSafe($host, true);

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertSame('abc', $result->data['fixupSha']);
        $this->assertArrayHasKey('message', $result->data);
    }

    public function testApplyWhenSafeOnCleanTreeWithoutCreatedCommitRequirementRewrites(): void
    {
        $this->expectCleanTree('abc123', true);
        $this->gitRepository->expects($this->once())->method('rebaseAutosquash')->with('abc123');

        $result = $this->handler->applyWhenSafe(CommandResponse::success(), false);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['rewritten']);
        $this->assertSame([], $result->getWarnings());
    }

    private function expectCleanTree(string $baseSha, bool $hasFixups): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getMergeBase')->with($this->baseBranch, 'HEAD')->willReturn($baseSha);
        $this->gitRepository->method('hasFixupCommits')->with($baseSha)->willReturn($hasFixups);
    }

    private function messageKey(mixed $message): ?string
    {
        return $message instanceof MessageRef ? $message->key : null;
    }
}
