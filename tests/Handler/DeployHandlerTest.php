<?php

namespace App\Tests\Handler;

use App\Handler\DeployHandler;
use App\Service\GitRepository;
use App\Tests\CommandTestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Style\SymfonyStyle;

class DeployHandlerTest extends CommandTestCase
{
    use ProphecyTrait;

    protected function setUp(): void
    {
        parent::setUp();

        // DeployHandlerTest checks output text, so use real TranslationService
        // This is acceptable since DeployHandler is the class under test
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new \App\Service\TranslationService('en', $translationsPath);
    }

    public function testHandle(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->getCurrentBranchName()->willReturn($releaseBranch);
        $gitRepository->checkout('main')->shouldBeCalled();
        $gitRepository->pull('origin', 'main')->shouldBeCalled();
        $gitRepository->merge($releaseBranch)->shouldBeCalled();
        $gitRepository->tag('v' . $version, 'Release v' . $version)->shouldBeCalled();
        $gitRepository->pushTags('origin')->shouldBeCalled();
        $gitRepository->checkout('develop')->shouldBeCalled();
        $gitRepository->pull('origin', 'develop')->shouldBeCalled();
        $gitRepository->rebase('main')->shouldBeCalled();
        $gitRepository->forcePushWithLeaseRemote('origin', 'develop')->shouldBeCalled();
        $gitRepository->localBranchExists($releaseBranch)->willReturn(true);
        $gitRepository->remoteBranchExists('origin', $releaseBranch)->willReturn(true);
        $gitRepository->deleteBranch($releaseBranch, true)->shouldBeCalled();
        $gitRepository->deleteRemoteBranch('origin', $releaseBranch)->shouldBeCalled();

        $logger = $this->createMock(\App\Service\Logger::class);
        $handler = new DeployHandler($gitRepository->reveal(), 'origin/develop', $this->translationService, $logger);
        $response = $handler->handle($io->reveal());

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleWithNoLocalBranch(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->getCurrentBranchName()->willReturn($releaseBranch);
        $gitRepository->checkout('main')->shouldBeCalled();
        $gitRepository->pull('origin', 'main')->shouldBeCalled();
        $gitRepository->merge($releaseBranch)->shouldBeCalled();
        $gitRepository->tag('v' . $version, 'Release v' . $version)->shouldBeCalled();
        $gitRepository->pushTags('origin')->shouldBeCalled();
        $gitRepository->checkout('develop')->shouldBeCalled();
        $gitRepository->pull('origin', 'develop')->shouldBeCalled();
        $gitRepository->rebase('main')->shouldBeCalled();
        $gitRepository->forcePushWithLeaseRemote('origin', 'develop')->shouldBeCalled();
        $gitRepository->localBranchExists($releaseBranch)->willReturn(false);
        $gitRepository->remoteBranchExists('origin', $releaseBranch)->willReturn(true);
        $gitRepository->deleteBranch($releaseBranch)->shouldNotBeCalled();
        $gitRepository->deleteRemoteBranch('origin', $releaseBranch)->shouldBeCalled();

        $logger = $this->createMock(\App\Service\Logger::class);
        $handler = new DeployHandler($gitRepository->reveal(), 'origin/develop', $this->translationService, $logger);
        $response = $handler->handle($io->reveal());

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleWithNoRemoteBranch(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->getCurrentBranchName()->willReturn($releaseBranch);
        $gitRepository->checkout('main')->shouldBeCalled();
        $gitRepository->pull('origin', 'main')->shouldBeCalled();
        $gitRepository->merge($releaseBranch)->shouldBeCalled();
        $gitRepository->tag('v' . $version, 'Release v' . $version)->shouldBeCalled();
        $gitRepository->pushTags('origin')->shouldBeCalled();
        $gitRepository->checkout('develop')->shouldBeCalled();
        $gitRepository->pull('origin', 'develop')->shouldBeCalled();
        $gitRepository->rebase('main')->shouldBeCalled();
        $gitRepository->forcePushWithLeaseRemote('origin', 'develop')->shouldBeCalled();
        $gitRepository->localBranchExists($releaseBranch)->willReturn(true);
        $gitRepository->remoteBranchExists('origin', $releaseBranch)->willReturn(false);
        $gitRepository->deleteBranch($releaseBranch, false)->shouldBeCalled();
        $gitRepository->deleteRemoteBranch('origin', $releaseBranch)->shouldNotBeCalled();

        $logger = $this->createMock(\App\Service\Logger::class);
        $handler = new DeployHandler($gitRepository->reveal(), 'origin/develop', $this->translationService, $logger);
        $response = $handler->handle($io->reveal());

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleWithNoLocalAndRemoteBranches(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->getCurrentBranchName()->willReturn($releaseBranch);
        $gitRepository->checkout('main')->shouldBeCalled();
        $gitRepository->pull('origin', 'main')->shouldBeCalled();
        $gitRepository->merge($releaseBranch)->shouldBeCalled();
        $gitRepository->tag('v' . $version, 'Release v' . $version)->shouldBeCalled();
        $gitRepository->pushTags('origin')->shouldBeCalled();
        $gitRepository->checkout('develop')->shouldBeCalled();
        $gitRepository->pull('origin', 'develop')->shouldBeCalled();
        $gitRepository->rebase('main')->shouldBeCalled();
        $gitRepository->forcePushWithLeaseRemote('origin', 'develop')->shouldBeCalled();
        $gitRepository->localBranchExists($releaseBranch)->willReturn(false);
        $gitRepository->remoteBranchExists('origin', $releaseBranch)->willReturn(false);
        $gitRepository->deleteBranch($releaseBranch)->shouldNotBeCalled();
        $gitRepository->deleteRemoteBranch('origin', $releaseBranch)->shouldNotBeCalled();

        $logger = $this->createMock(\App\Service\Logger::class);
        $handler = new DeployHandler($gitRepository->reveal(), 'origin/develop', $this->translationService, $logger);
        $response = $handler->handle($io->reveal());

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleOnNonReleaseBranch(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $gitRepository->getCurrentBranchName()->willReturn('feat/some-feature');

        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->method('addSection');
        $logger->method('addError');

        $handler = new DeployHandler($gitRepository->reveal(), 'origin/develop', $this->translationService, $logger);
        $response = $handler->handle($io->reveal());

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleWithStaleRemoteRef(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->getCurrentBranchName()->willReturn($releaseBranch);
        $gitRepository->checkout('main')->shouldBeCalled();
        $gitRepository->pull('origin', 'main')->shouldBeCalled();
        $gitRepository->merge($releaseBranch)->shouldBeCalled();
        $gitRepository->tag('v' . $version, 'Release v' . $version)->shouldBeCalled();
        $gitRepository->pushTags('origin')->shouldBeCalled();
        $gitRepository->checkout('develop')->shouldBeCalled();
        $gitRepository->pull('origin', 'develop')->shouldBeCalled();
        $gitRepository->rebase('main')->shouldBeCalled();
        $gitRepository->forcePushWithLeaseRemote('origin', 'develop')->shouldBeCalled();
        $gitRepository->localBranchExists($releaseBranch)->willReturn(true);
        $gitRepository->remoteBranchExists('origin', $releaseBranch)->willReturn(false);

        // First delete attempt fails (stale ref issue), then force delete succeeds
        $gitRepository->deleteBranch($releaseBranch, false)->willThrow(new \RuntimeException('not fully merged'));
        $gitRepository->deleteBranchForce($releaseBranch)->shouldBeCalled();

        $gitRepository->deleteRemoteBranch('origin', $releaseBranch)->shouldNotBeCalled();

        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->expects($this->atLeastOnce())
            ->method('addWarning')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->messageRefWithKey('branches.clean.force_delete_warning'));

        $handler = new DeployHandler($gitRepository->reveal(), 'origin/develop', $this->translationService, $logger);
        $handler->handle($io->reveal());
    }

    public function testHandleWithStaleRemoteRefForceDeleteFails(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->getCurrentBranchName()->willReturn($releaseBranch);
        $gitRepository->checkout('main')->shouldBeCalled();
        $gitRepository->pull('origin', 'main')->shouldBeCalled();
        $gitRepository->merge($releaseBranch)->shouldBeCalled();
        $gitRepository->tag('v' . $version, 'Release v' . $version)->shouldBeCalled();
        $gitRepository->pushTags('origin')->shouldBeCalled();
        $gitRepository->checkout('develop')->shouldBeCalled();
        $gitRepository->pull('origin', 'develop')->shouldBeCalled();
        $gitRepository->rebase('main')->shouldBeCalled();
        $gitRepository->forcePushWithLeaseRemote('origin', 'develop')->shouldBeCalled();
        $gitRepository->localBranchExists($releaseBranch)->willReturn(true);
        $gitRepository->remoteBranchExists('origin', $releaseBranch)->willReturn(false);

        // First delete attempt fails (stale ref issue), force delete also fails
        $gitRepository->deleteBranch($releaseBranch, false)->willThrow(new \RuntimeException('not fully merged'));
        $gitRepository->deleteBranchForce($releaseBranch)->willThrow(new \RuntimeException('Force delete failed'));

        $gitRepository->deleteRemoteBranch('origin', $releaseBranch)->shouldNotBeCalled();

        $logger = $this->createMock(\App\Service\Logger::class);
        // When force delete fails, only the cleanup warning is logged (not the force delete warning)
        $logger->expects($this->once())
            ->method('addWarning')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->messageRefWithKey('deploy.warning_branch_cleanup'));

        $handler = new DeployHandler($gitRepository->reveal(), 'origin/develop', $this->translationService, $logger);
        $handler->handle($io->reveal());
    }

    public function testHandleWithRemoteExistsAndDeletionFails(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->getCurrentBranchName()->willReturn($releaseBranch);
        $gitRepository->checkout('main')->shouldBeCalled();
        $gitRepository->pull('origin', 'main')->shouldBeCalled();
        $gitRepository->merge($releaseBranch)->shouldBeCalled();
        $gitRepository->tag('v' . $version, 'Release v' . $version)->shouldBeCalled();
        $gitRepository->pushTags('origin')->shouldBeCalled();
        $gitRepository->checkout('develop')->shouldBeCalled();
        $gitRepository->pull('origin', 'develop')->shouldBeCalled();
        $gitRepository->rebase('main')->shouldBeCalled();
        $gitRepository->forcePushWithLeaseRemote('origin', 'develop')->shouldBeCalled();
        $gitRepository->localBranchExists($releaseBranch)->willReturn(true);
        $gitRepository->remoteBranchExists('origin', $releaseBranch)->willReturn(true);

        // Delete fails when remote exists
        $gitRepository->deleteBranch($releaseBranch, true)->willThrow(new \RuntimeException('Deletion failed'));
        $gitRepository->deleteBranchForce($releaseBranch)->shouldNotBeCalled();
        $gitRepository->deleteRemoteBranch('origin', $releaseBranch)->shouldBeCalled();

        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->expects($this->once())
            ->method('addWarning')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, $this->messageRefWithKey('deploy.warning_branch_cleanup'));

        $handler = new DeployHandler($gitRepository->reveal(), 'origin/develop', $this->translationService, $logger);
        $handler->handle($io->reveal());
    }
}
