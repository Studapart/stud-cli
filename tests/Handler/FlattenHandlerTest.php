<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Exception\GitException;
use App\Handler\FlattenHandler;
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
        $this->assertFalse($result->data['rewritten']);
    }

    public function testHandleWithNoFixupCommits(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->expects($this->once())->method('getMergeBase')->with($this->baseBranch, 'HEAD')->willReturn('abc123');
        $this->gitRepository->expects($this->once())->method('hasFixupCommits')->with('abc123')->willReturn(false);
        $this->gitRepository->expects($this->never())->method('rebaseAutosquash');

        $result = $this->handler->handle();

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertNotEmpty($result->getNotices());
    }

    public function testHandleWithFixupCommitsKeepsPleaseHint(): void
    {
        $this->expectRewrite();

        $result = $this->handler->handle();

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['rewritten']);
        $this->assertNotEmpty($result->getWarnings());
    }

    public function testHandleWithFixupCommitsOmitsPleaseHint(): void
    {
        $this->expectRewrite();

        $result = $this->handler->handle(false);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->data['rewritten']);
        $this->assertSame([], $result->getWarnings());
    }

    public function testHandleWithRebaseFailure(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getMergeBase')->willReturn('abc123');
        $this->gitRepository->method('hasFixupCommits')->willReturn(true);
        $this->gitRepository->method('rebaseAutosquash')->willThrowException(new \RuntimeException('Rebase failed'));

        $result = $this->handler->handle();

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
    }

    public function testHandleWithRebaseFailureGitException(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getMergeBase')->willReturn('abc123');
        $this->gitRepository->method('hasFixupCommits')->willReturn(true);
        $this->gitRepository->method('rebaseAutosquash')->willThrowException(
            new GitException('Git command failed: git rebase', 'fatal: could not read object', null),
        );

        $result = $this->handler->handle();

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertNotEmpty($result->getTechnicalDetails());
    }

    private function expectRewrite(): void
    {
        $this->gitRepository->method('getPorcelainStatus')->willReturn('');
        $this->gitRepository->method('getMergeBase')->with($this->baseBranch, 'HEAD')->willReturn('abc123');
        $this->gitRepository->method('hasFixupCommits')->with('abc123')->willReturn(true);
        $this->gitRepository->expects($this->once())->method('rebaseAutosquash')->with('abc123');
    }
}
