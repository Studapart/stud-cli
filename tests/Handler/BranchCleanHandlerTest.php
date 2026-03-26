<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\BranchCleanHandler;
use App\Service\BranchDeletionEligibilityResolver;
use App\Service\GithubProvider;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchCleanHandlerTest extends CommandTestCase
{
    private BranchCleanHandler $handler;
    private GithubProvider&MockObject $githubProvider;
    private Logger&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->githubProvider = $this->createMock(GithubProvider::class);
        $this->logger = $this->createMock(Logger::class);
        $this->logger->method('section');
        $this->logger->method('note');
        $this->logger->method('text');
        $this->logger->method('writeln');
        $this->logger->method('warning');
        $this->logger->method('success');
        $this->logger->method('confirm')->willReturn(true);
        $this->logger->method('ask')->willReturn('develop');

        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $this->handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            'origin/develop',
            $this->translationService,
            $this->logger
        );
    }

    public function testHandleQuietDeletesOnlyYesBranches(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/merged', 'feat/manual']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/merged', false],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturnCallback(
            fn (string $branch, string $base) => $branch === 'feat/merged' && $base === 'origin/develop'
        );
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/merged', false);

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleSkipsOpenPullRequestBranch(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/open-pr']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/open-pr']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturn(true);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('getAllPullRequests')->willReturn([
            [
                'state' => 'open',
                'head' => ['ref' => 'feat/open-pr', 'repo' => ['full_name' => 'owner/repo']],
                'base' => ['repo' => ['full_name' => 'owner/repo']],
            ],
        ]);

        $this->gitRepository->expects($this->never())->method('deleteBranch');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleInteractiveCanConfirmManualBranch(): void
    {
        $this->logger->method('confirm')->willReturn(true);
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/manual']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', false],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/manual', false],
        ]);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/manual', false);

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleWithDeletionFailureFallsBackToForceDelete(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/fallback']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/fallback', false],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);
        $this->gitRepository->method('deleteBranch')->willThrowException(new \RuntimeException('branch is not fully merged'));
        $this->gitRepository->expects($this->once())->method('deleteBranchForce')->with('feat/fallback');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io, true);

        $this->assertSame(0, $result);
    }

    public function testHandleWithRemoteBranchDeleteConfirmed(): void
    {
        $this->logger->method('confirm')->willReturnOnConsecutiveCalls(true, true);
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/remote']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/remote']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/remote', true],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);
        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/remote', true);
        $this->gitRepository->expects($this->once())->method('deleteRemoteBranch')->with('origin', 'feat/remote');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io, false);

        $this->assertSame(0, $result);
    }

    public function testHandleRemoteBranchDeletionQuietPath(): void
    {
        $this->logger->expects($this->never())->method('confirm');
        $this->callPrivateMethod($this->handler, 'handleRemoteBranchDeletion', ['feat/quiet', true]);
        $this->assertTrue(true);
    }

    public function testResolveBaseBranchReturnsNullWhenPromptedBranchMissingRemotely(): void
    {
        $this->logger->method('ask')->willReturn('feature/unknown-base');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', false],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feature/unknown-base', false],
        ]);

        $resolved = $this->callPrivateMethod($this->handler, 'resolveBaseBranch', [false]);
        $this->assertNull($resolved);
    }

    public function testShouldExitEarlyReturnsFalseWhenManualBranchesExist(): void
    {
        $result = $this->callPrivateMethod($this->handler, 'shouldExitEarly', [[], [], false, [['branch' => 'feat/x', 'reason' => 'provider_unavailable', 'remote_exists' => false]]]);
        $this->assertFalse($result);
    }

    public function testShouldExitEarlyReturnsTrueWhenOnlyCurrentSkipped(): void
    {
        $result = $this->callPrivateMethod($this->handler, 'shouldExitEarly', [[], [], true, []]);
        $this->assertTrue($result);
    }

    public function testConfirmDeletionReturnsFalseWhenCancelled(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('confirm')->willReturn(false);
        $logger->method('text');
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            'origin/develop',
            $this->translationService,
            $logger
        );

        $confirmed = $this->callPrivateMethod($handler, 'confirmDeletion', [['feat/a'], [], false]);
        $this->assertFalse($confirmed);
    }

    public function testHandleDeleteFailureNonFallbackReturnsFalse(): void
    {
        $this->gitRepository->method('remoteBranchExists')->with('origin', 'feat/no-fallback')->willReturn(true);
        $result = $this->callPrivateMethod(
            $this->handler,
            'handleDeleteFailure',
            ['feat/no-fallback', new \RuntimeException('some failure')]
        );
        $this->assertFalse($result);
    }

    public function testAttemptForceDeleteReturnsFalseOnException(): void
    {
        $this->gitRepository->method('deleteBranchForce')->willThrowException(new \RuntimeException('force failed'));
        $result = $this->callPrivateMethod($this->handler, 'attemptForceDelete', ['feat/fail-force']);
        $this->assertFalse($result);
    }

    public function testDeleteRemoteBranchHandlesException(): void
    {
        $this->gitRepository->method('deleteRemoteBranch')->willThrowException(new \RuntimeException('remote fail'));
        $this->callPrivateMethod($this->handler, 'deleteRemoteBranch', ['feat/remote-fail']);
        $this->assertTrue(true);
    }

    public function testHandleReturnsEarlyWhenDeletionCancelled(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('note');
        $logger->method('text');
        $logger->method('writeln');
        $logger->method('warning');
        $logger->method('success');
        $logger->expects($this->once())->method('confirm')->willReturn(false);
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            'origin/develop',
            $this->translationService,
            $logger
        );

        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/cancel']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/cancel', false],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $handler->handle($io, false);
        $this->assertSame(0, $result);
    }

    public function testHandleCurrentBranchSkippedTriggersNotifyPath(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn(['feat/current', 'feat/merged']);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/current');
        $this->gitRepository->method('remoteBranchExists')->willReturnMap([
            ['origin', 'develop', true],
            ['origin', 'main', false],
            ['origin', 'master', false],
            ['origin', 'feat/merged', false],
        ]);
        $this->gitBranchService->method('isBranchMergedInto')->willReturnCallback(fn (string $b) => $b === 'feat/merged');
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $this->gitRepository->expects($this->once())->method('deleteBranch')->with('feat/merged', false);
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $this->assertSame(0, $this->handler->handle($io, true));
    }

    public function testAddManuallyConfirmedBranchesSupportsSkipAndRemoteAppend(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->exactly(2))->method('confirm')->willReturnOnConsecutiveCalls(false, true);
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            'origin/develop',
            $this->translationService,
            $logger
        );

        $local = [];
        $remote = [];
        $manual = [
            ['branch' => 'feat/skip', 'reason' => 'provider_unavailable', 'remote_exists' => false],
            ['branch' => 'feat/remote-manual', 'reason' => 'provider_unavailable', 'remote_exists' => true],
        ];

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('addManuallyConfirmedBranches');
        $method->setAccessible(true);
        $args = [$manual, &$local, &$remote];
        $method->invokeArgs($handler, $args);

        $this->assertSame([], $local);
        $this->assertSame(['feat/remote-manual'], $remote);
    }

    public function testDisplayManualBranchesReportWithEntries(): void
    {
        $this->callPrivateMethod($this->handler, 'displayManualBranchesReport', [[
            ['branch' => 'feat/manual', 'reason' => 'provider_unavailable', 'remote_exists' => false],
        ]]);
        $this->assertTrue(true);
    }

    public function testDeleteLocalOnlyBranchesSkipsProtected(): void
    {
        $result = $this->callPrivateMethod($this->handler, 'deleteLocalOnlyBranches', [['main']]);
        $this->assertSame(0, $result);
    }

    public function testDeleteBranchesWithRemoteSkipsProtected(): void
    {
        $result = $this->callPrivateMethod($this->handler, 'deleteBranchesWithRemote', [['main'], true]);
        $this->assertSame(0, $result);
    }

    public function testHandleRemoteBranchDeletionInteractiveKeepsRemoteWhenDenied(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('confirm')->willReturn(false);
        $logger->expects($this->once())->method('writeln');
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            'origin/develop',
            $this->translationService,
            $logger
        );

        $this->callPrivateMethod($handler, 'handleRemoteBranchDeletion', ['feat/keep', false]);
        $this->assertTrue(true);
    }

    public function testResolveBaseBranchInteractiveValidationAndSuccessPath(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->method('ask')->willReturnCallback(function (string $question, string $default, callable $validator): string {
            try {
                $validator('');
            } catch (\RuntimeException) {
                // expected validation exception path
            }

            return $validator('develop');
        });
        $resolver = new BranchDeletionEligibilityResolver($this->gitRepository, $this->gitBranchService, $this->githubProvider);
        $handler = new BranchCleanHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $resolver,
            null,
            $this->translationService,
            $logger
        );

        $this->gitRepository->expects($this->exactly(4))
            ->method('remoteBranchExists')
            ->willReturnOnConsecutiveCalls(false, false, false, true);

        $resolved = $this->callPrivateMethod($handler, 'resolveBaseBranch', [false]);
        $this->assertSame('origin/develop', $resolved);
    }
}
