<?php

namespace App\Handler;

use App\Service\GitRepository;
use Symfony\Component\Console\Style\SymfonyStyle;

class DeployHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
    ) {
    }

    public function handle(SymfonyStyle $io): void
    {
        $io->section('Starting deployment process');

        $currentBranch = $this->gitRepository->getCurrentBranchName();
        if (!str_starts_with($currentBranch, 'release/v')) {
            $io->error('You must be on a release branch to deploy.');
            return;
        }

        $version = str_replace('release/v', '', $currentBranch);

        // Deploy to main
        $this->gitRepository->checkout('main');
        $this->gitRepository->pull('origin', 'main');
        $this->gitRepository->merge($currentBranch);
        $this->gitRepository->tag('v' . $version, 'Release v' . $version);
        $this->gitRepository->pushTags('origin');
        $io->text('Deployed to main and tagged.');

        // Update develop
        $this->gitRepository->checkout('develop');
        $this->gitRepository->pull('origin', 'develop');
        $this->gitRepository->rebase('main');
        $this->gitRepository->forcePushWithLeaseRemote('origin', 'develop');
        $io->text('Updated develop branch.');

        // Cleanup
        if ($this->gitRepository->localBranchExists($currentBranch)) {
            $this->gitRepository->deleteBranch($currentBranch);
        }
        if ($this->gitRepository->remoteBranchExists('origin', $currentBranch)) {
            $this->gitRepository->deleteRemoteBranch('origin', $currentBranch);
        }
        $io->text('Cleaned up release branch.');

        $io->success('Release v' . $version . ' successfully deployed to main. develop has been rebased and force-pushed. Branches cleaned up.');
    }
}
