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
        $gitRepository->deleteBranch($releaseBranch)->shouldBeCalled();
        $gitRepository->deleteRemoteBranch('origin', $releaseBranch)->shouldBeCalled();

        $handler = new DeployHandler($gitRepository->reveal());
        $handler->handle($io->reveal());
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

        $handler = new DeployHandler($gitRepository->reveal());
        $handler->handle($io->reveal());
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
        $gitRepository->deleteBranch($releaseBranch)->shouldBeCalled();
        $gitRepository->deleteRemoteBranch('origin', $releaseBranch)->shouldNotBeCalled();

        $handler = new DeployHandler($gitRepository->reveal());
        $handler->handle($io->reveal());
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

        $handler = new DeployHandler($gitRepository->reveal());
        $handler->handle($io->reveal());
    }

    public function testHandleOnNonReleaseBranch(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $gitRepository->getCurrentBranchName()->willReturn('feat/some-feature');
        $io->title('Starting deployment process')->shouldBeCalled();
        $io->error('You must be on a release branch to deploy.')->shouldBeCalled();

        $handler = new DeployHandler($gitRepository->reveal());
        $handler->handle($io->reveal());
    }
}
