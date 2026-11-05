<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\ProcessFactory;
use App\Tests\Support\GitRepositoryTestKit;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Wire contract for GitRepository and its git collaborators.
 * Intention: each row pins the argv and execution mode (mustRun = throws on failure,
 * run = quiet) of one argv-building public method or one migrated scenario.
 * Behavior (output parsing, exceptions) lives in GitRepositoryTest.
 */
final class GitRepositoryWireContractTest extends TestCase
{
    private const string OUTPUT = 'abc123';

    /** @var list<array{0: list<string>, 1: 'mustRun'|'run'|null}> */
    private array $calls = [];

    private GitRepository $gitRepository;

    protected function setUp(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $flysystem = new FlysystemFilesystem($adapter);
        $fileSystem = new FileSystem($flysystem);
        $factory = $this->createStub(ProcessFactory::class);
        $factory->method('create')->willReturnCallback(function (array $command): Process {
            $this->calls[] = [$command, null];
            $index = count($this->calls) - 1;
            $process = $this->createStub(Process::class);
            $process->method('isSuccessful')->willReturn(true);
            $process->method('getOutput')->willReturn(self::OUTPUT);
            $process->method('run')->willReturnCallback(function () use ($index): int {
                $this->calls[$index][1] = 'run';

                return 0;
            });
            $process->method('mustRun')->willReturnCallback(function () use ($index, $process): Process {
                $this->calls[$index][1] = 'mustRun';

                return $process;
            });

            return $process;
        });
        $this->gitRepository = GitRepositoryTestKit::create($factory, $fileSystem);
    }

    /**
     * @param \Closure(GitRepository): mixed $call
     * @param list<array{0: list<string>, 1: 'mustRun'|'run'}> $expected
     */
    #[DataProvider('wireFormatProvider')]
    public function testInvocationDocumentsWireFormat(\Closure $call, array $expected): void
    {
        $call($this->gitRepository);

        self::assertSame($expected, $this->calls);
    }

    public function testRebaseAutosquashDocumentsSequenceEditorWireFormat(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $flysystem = new FlysystemFilesystem($adapter);
        $fileSystem = new FileSystem($flysystem);
        $script = null;
        $calls = [];
        $factory = $this->createStub(ProcessFactory::class);
        $factory->method('create')->willReturnCallback(function (array $command) use (&$calls, &$script, $fileSystem): Process {
            $calls[] = [$command, null];
            $index = count($calls) - 1;
            $process = $this->createStub(Process::class);
            $process->method('setEnv')->willReturnCallback(function (array $env) use (&$script, $fileSystem, $process): Process {
                $script = $fileSystem->read($env['GIT_SEQUENCE_EDITOR']);

                return $process;
            });
            $process->method('mustRun')->willReturnCallback(function () use (&$calls, $index, $process): Process {
                $calls[$index][1] = 'mustRun';

                return $process;
            });

            return $process;
        });

        GitRepositoryTestKit::create($factory, $fileSystem)->rebaseAutosquash('abc123');

        self::assertSame([[['git', 'rebase', '-i', '--autosquash', 'abc123'], 'mustRun']], $calls);
        self::assertIsString($script);
        self::assertStringContainsString('fixup!', $script);
        self::assertStringContainsString('squash!', $script);
    }

    /**
     * @return iterable<string, array{\Closure(GitRepository): mixed, list<array{0: list<string>, 1: string}>}>
     */
    public static function wireFormatProvider(): iterable
    {
        yield from self::migratedResetRows();
        yield from self::migratedCommitRows();
        yield from self::migratedBranchRows();
        yield from self::migratedDeleteRows();
        yield from self::queryRevParseRows();
        yield from self::queryLogRows();
        yield from self::queryStatusRows();
    }

    /**
     * @return iterable<string, array{\Closure(GitRepository): mixed, list<array{0: list<string>, 1: string}>}>
     */
    private static function migratedResetRows(): iterable
    {
        yield 'testUndoLastCommitRunsReset' => [
            static fn (GitRepository $git): mixed => $git->undoLastCommit(),
            [[['git', 'reset', 'HEAD~1'], 'mustRun']],
        ];
        yield 'testForcePushWithLease' => [
            static fn (GitRepository $git): mixed => $git->forcePushWithLease(),
            [[['git', 'push', '--force-with-lease'], 'mustRun']],
        ];
        yield 'testForcePushWithLeaseRemote' => [
            static fn (GitRepository $git): mixed => $git->forcePushWithLeaseRemote('origin', 'main'),
            [[['git', 'push', '--force-with-lease', 'origin', 'main'], 'mustRun']],
        ];
        yield 'testStageAllChanges' => [
            static fn (GitRepository $git): mixed => $git->stageAllChanges(),
            [[['git', 'add', '-A'], 'mustRun']],
        ];
        yield 'testFetch' => [
            static fn (GitRepository $git): mixed => $git->fetch(),
            [[['git', 'fetch', 'origin'], 'mustRun']],
        ];
        yield 'testCreateBranch' => [
            static fn (GitRepository $git): mixed => $git->createBranch('feature/PROJ-123-my-feature', 'origin/develop'),
            [[['git', 'switch', '-c', 'feature/PROJ-123-my-feature', 'origin/develop'], 'mustRun']],
        ];
        yield 'testPushToOrigin' => [
            static fn (GitRepository $git): mixed => $git->pushToOrigin('my-branch'),
            [[['git', 'push', '--set-upstream', 'origin', 'my-branch'], 'run']],
        ];
    }

    /**
     * @return iterable<string, array{\Closure(GitRepository): mixed, list<array{0: list<string>, 1: string}>}>
     */
    private static function migratedCommitRows(): iterable
    {
        yield 'testCommitFixup' => [
            static fn (GitRepository $git): mixed => $git->commitFixup('abcdef'),
            [[['git', 'commit', '--fixup', 'abcdef'], 'mustRun']],
        ];
        yield 'testCommit' => [
            static fn (GitRepository $git): mixed => $git->commit('my message'),
            [[['git', 'commit', '-m', 'my message'], 'mustRun']],
        ];
        yield 'testPushHeadToOrigin' => [
            static fn (GitRepository $git): mixed => $git->pushHeadToOrigin(),
            [[['git', 'push', '--set-upstream', 'origin', 'HEAD'], 'run']],
        ];
        yield 'testRun' => [
            static fn (GitRepository $git): mixed => $git->run(['my', 'command']),
            [[['my', 'command'], 'mustRun']],
        ];
        yield 'testRunQuietly' => [
            static fn (GitRepository $git): mixed => $git->runQuietly(['my', 'command']),
            [[['my', 'command'], 'run']],
        ];
        yield 'testAdd' => [
            static fn (GitRepository $git): mixed => $git->add(['file1.txt', 'file2.txt']),
            [[['git', 'add', 'file1.txt', 'file2.txt'], 'mustRun']],
        ];
        yield 'testCheckout' => [
            static fn (GitRepository $git): mixed => $git->checkout('my-branch'),
            [[['git', 'checkout', 'my-branch'], 'mustRun']],
        ];
    }

    /**
     * @return iterable<string, array{\Closure(GitRepository): mixed, list<array{0: list<string>, 1: string}>}>
     */
    private static function migratedBranchRows(): iterable
    {
        yield 'testPull' => [
            static fn (GitRepository $git): mixed => $git->pull('origin', 'main'),
            [[['git', 'pull', 'origin', 'main'], 'mustRun']],
        ];
        yield 'testPullWithRebase' => [
            static fn (GitRepository $git): mixed => $git->pullWithRebase('origin', 'feat/PROJ-123'),
            [[['git', 'pull', '--rebase', 'origin', 'feat/PROJ-123'], 'mustRun']],
        ];
        yield 'testMerge' => [
            static fn (GitRepository $git): mixed => $git->merge('my-branch'),
            [[['git', 'merge', '--no-ff', 'my-branch'], 'mustRun']],
        ];
        yield 'testTag' => [
            static fn (GitRepository $git): mixed => $git->tag('v1.2.3', 'Release v1.2.3'),
            [[['git', 'tag', '-a', 'v1.2.3', '-m', 'Release v1.2.3'], 'mustRun']],
        ];
        yield 'testPushTags' => [
            static fn (GitRepository $git): mixed => $git->pushTags('origin'),
            [[['git', 'push', '--tags', 'origin', 'main'], 'mustRun']],
        ];
        yield 'testRebase' => [
            static fn (GitRepository $git): mixed => $git->rebase('main'),
            [[['git', 'rebase', 'main'], 'mustRun']],
        ];
        yield 'testRebaseAbort' => [
            static fn (GitRepository $git): mixed => $git->rebaseAbort(),
            [[['git', 'rebase', '--abort'], 'mustRun']],
        ];
    }

    /**
     * @return iterable<string, array{\Closure(GitRepository): mixed, list<array{0: list<string>, 1: string}>}>
     */
    private static function migratedDeleteRows(): iterable
    {
        yield 'testDeleteBranch' => [
            static fn (GitRepository $git): mixed => $git->deleteBranch('my-branch'),
            [[['git', 'branch', '-d', 'my-branch'], 'mustRun']],
        ];
        yield 'testDeleteBranchWithRemoteExistsTrue' => [
            static fn (GitRepository $git): mixed => $git->deleteBranch('my-branch', true),
            [[['git', 'branch', '-d', 'my-branch'], 'mustRun']],
        ];
        yield 'testDeleteBranchWithRemoteExistsFalse' => [
            static fn (GitRepository $git): mixed => $git->deleteBranch('my-branch', false),
            [
                [['git', 'fetch', '--prune', 'origin'], 'mustRun'],
                [['git', 'branch', '-d', 'my-branch'], 'mustRun'],
            ],
        ];
        yield 'testDeleteBranchForce' => [
            static fn (GitRepository $git): mixed => $git->deleteBranchForce('my-branch'),
            [[['git', 'branch', '-D', 'my-branch'], 'mustRun']],
        ];
        yield 'testPruneRemoteTrackingRefs' => [
            static fn (GitRepository $git): mixed => $git->pruneRemoteTrackingRefs(),
            [[['git', 'fetch', '--prune', 'origin'], 'mustRun']],
        ];
        yield 'testPruneRemoteTrackingRefsWithCustomRemote' => [
            static fn (GitRepository $git): mixed => $git->pruneRemoteTrackingRefs('upstream'),
            [[['git', 'fetch', '--prune', 'upstream'], 'mustRun']],
        ];
        yield 'testDeleteRemoteBranch' => [
            static fn (GitRepository $git): mixed => $git->deleteRemoteBranch('origin', 'my-branch'),
            [[['git', 'push', 'origin', '--delete', 'my-branch'], 'mustRun']],
        ];
    }

    /**
     * @return iterable<string, array{\Closure(GitRepository): mixed, list<array{0: list<string>, 1: string}>}>
     */
    private static function queryRevParseRows(): iterable
    {
        yield 'getIssueKeyFromBranchName' => [
            static fn (GitRepository $git): mixed => $git->getIssueKeyFromBranchName(),
            [[['git', 'rev-parse', '--abbrev-ref', 'HEAD'], 'run']],
        ];
        yield 'getCurrentBranchName' => [
            static fn (GitRepository $git): mixed => $git->getCurrentBranchName(),
            [[['git', 'rev-parse', '--abbrev-ref', 'HEAD'], 'mustRun']],
        ];
        yield 'getUpstreamBranch' => [
            static fn (GitRepository $git): mixed => $git->getUpstreamBranch(),
            [[['git', 'rev-parse', '--abbrev-ref', '@{u}'], 'run']],
        ];
        yield 'isHeadPushed' => [
            static fn (GitRepository $git): mixed => $git->isHeadPushed(),
            [
                [['git', 'rev-parse', '--abbrev-ref', '@{u}'], 'run'],
                [['git', 'rev-parse', 'HEAD'], 'run'],
                [['git', 'rev-parse', '@{u}'], 'run'],
            ],
        ];
        yield 'hasAtLeastOneCommit' => [
            static fn (GitRepository $git): mixed => $git->hasAtLeastOneCommit(),
            [[['git', 'rev-parse', 'HEAD'], 'run']],
        ];
        yield 'localBranchExists' => [
            static fn (GitRepository $git): mixed => $git->localBranchExists('my-branch'),
            [[['git', 'rev-parse', '--verify', '--quiet', 'my-branch'], 'run']],
        ];
    }

    /**
     * @return iterable<string, array{\Closure(GitRepository): mixed, list<array{0: list<string>, 1: string}>}>
     */
    private static function queryLogRows(): iterable
    {
        yield 'tryRebase' => [
            static fn (GitRepository $git): mixed => $git->tryRebase('origin/develop'),
            [[['git', 'rebase', 'origin/develop'], 'run']],
        ];
        yield 'isAncestor' => [
            static fn (GitRepository $git): mixed => $git->isAncestor('origin/develop', 'HEAD'),
            [[['git', 'merge-base', '--is-ancestor', 'origin/develop', 'HEAD'], 'run']],
        ];
        yield 'hasFixupCommits' => [
            static fn (GitRepository $git): mixed => $git->hasFixupCommits('abc123'),
            [[['git', 'log', 'abc123..HEAD', '--format=%s', '--grep=^fixup!', '--grep=^squash!'], 'run']],
        ];
        yield 'findLatestLogicalSha' => [
            static fn (GitRepository $git): mixed => $git->findLatestLogicalSha('origin/develop'),
            [[['git', 'log', 'origin/develop..HEAD', '--format=%H', '--grep=^fixup!', '--grep=^squash!', '--invert-grep', '--max-count=1'], 'run']],
        ];
        yield 'findFirstLogicalSha' => [
            static fn (GitRepository $git): mixed => $git->findFirstLogicalSha('abcdef'),
            [[['git', 'rev-list', '--reverse', 'abcdef..HEAD'], 'run']],
        ];
        yield 'getMergeBase' => [
            static fn (GitRepository $git): mixed => $git->getMergeBase('origin/develop', 'HEAD'),
            [[['git', 'merge-base', 'origin/develop', 'HEAD'], 'mustRun']],
        ];
    }

    /**
     * @return iterable<string, array{\Closure(GitRepository): mixed, list<array{0: list<string>, 1: string}>}>
     */
    private static function queryStatusRows(): iterable
    {
        yield 'getPorcelainStatus' => [
            static fn (GitRepository $git): mixed => $git->getPorcelainStatus(),
            [[['git', 'status', '--porcelain'], 'mustRun']],
        ];
        yield 'hasStagedChanges' => [
            static fn (GitRepository $git): mixed => $git->hasStagedChanges(),
            [[['git', 'diff', '--cached', '--quiet'], 'run']],
        ];
        yield 'getCommitMessage' => [
            static fn (GitRepository $git): mixed => $git->getCommitMessage('abcdef'),
            [[['git', 'log', '-1', '--pretty=%B', 'abcdef'], 'mustRun']],
        ];
        yield 'remoteBranchExists' => [
            static fn (GitRepository $git): mixed => $git->remoteBranchExists('origin', 'my-branch'),
            [[['git', 'ls-remote', '--heads', 'origin', 'my-branch'], 'run']],
        ];
        yield 'parseGitUrl' => [
            static fn (GitRepository $git): mixed => $git->parseGitUrl('origin'),
            [[['git', 'config', '--get', 'remote.origin.url'], 'run']],
        ];
        yield 'getProjectConfigPath' => [
            static fn (GitRepository $git): mixed => $git->getProjectConfigPath(),
            [[['git', 'rev-parse', '--git-dir'], 'run']],
        ];
    }
}
