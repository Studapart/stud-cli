<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitRepository;
use App\Service\SubmitBranchInspector;
use App\Service\SubmitGit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SubmitBranchInspectorTest extends TestCase
{
    private GitRepository&Stub $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createStub(GitRepository::class);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function branchProvider(): iterable
    {
        yield 'configured base branch' => ['develop', true];
        yield 'main' => ['main', true];
        yield 'master' => ['master', true];
        yield 'feature branch' => ['feat/SCI-1-thing', false];
    }

    #[DataProvider('branchProvider')]
    public function testIsBaseBranch(string $branch, bool $expected): void
    {
        $this->assertSame($expected, $this->inspector('origin/develop')->isBaseBranch($branch));
    }

    public function testFirstLogicalCommitMessageReadsMessageOfFirstLogicalCommit(): void
    {
        $repository = $this->createMock(GitRepository::class);
        $repository->expects($this->once())->method('getMergeBase')->with('origin/develop', 'HEAD')->willReturn('base-sha');
        $repository->expects($this->once())->method('findFirstLogicalSha')->with('base-sha')->willReturn('first-sha');
        $repository->expects($this->once())->method('getCommitMessage')->with('first-sha')->willReturn('feat: thing');
        $this->repository = $repository;

        $this->assertSame('feat: thing', $this->inspector('origin/develop')->firstLogicalCommitMessage());
    }

    public function testFirstLogicalCommitMessageIsNullWithoutLogicalCommit(): void
    {
        $this->repository->method('getMergeBase')->willReturn('base-sha');
        $this->repository->method('findFirstLogicalSha')->willReturn(null);

        $this->assertNull($this->inspector('origin/develop')->firstLogicalCommitMessage());
    }

    public function testResolveIssueKeyPrefersBranchName(): void
    {
        $this->repository->method('getIssueKeyFromBranchName')->willReturn('SCI-1');

        $this->assertSame('SCI-1', $this->inspector('origin/develop')->resolveIssueKey('feat: thing [SCI-2]'));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string, 2: string|null}>
     */
    public static function commitMessageKeyProvider(): iterable
    {
        yield 'no branch key, bracketed key' => [null, 'feat: thing [sci-2]', 'sci-2'];
        yield 'empty branch key, bracketed key' => ['', 'feat: thing [SCI-2]', 'SCI-2'];
        yield 'no key anywhere' => [null, 'feat: thing', null];
    }

    #[DataProvider('commitMessageKeyProvider')]
    public function testResolveIssueKeyFallsBackToCommitMessage(?string $branchKey, string $message, ?string $expected): void
    {
        $this->repository->method('getIssueKeyFromBranchName')->willReturn($branchKey);

        $this->assertSame($expected, $this->inspector('origin/develop')->resolveIssueKey($message));
    }

    private function inspector(string $baseBranch): SubmitBranchInspector
    {
        return new SubmitBranchInspector(new SubmitGit($this->repository, $baseBranch));
    }
}
