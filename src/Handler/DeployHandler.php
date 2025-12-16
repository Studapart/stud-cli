<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class DeployHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io): void
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.section'));

        $currentBranch = $this->gitRepository->getCurrentBranchName();
        if (! str_starts_with($currentBranch, 'release/v')) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.error_not_release'));

            return;
        }

        $version = str_replace('release/v', '', $currentBranch);

        // Deploy to main
        $this->gitRepository->checkout('main');
        $this->gitRepository->pull('origin', 'main');
        $this->gitRepository->merge($currentBranch);
        $this->gitRepository->tag('v' . $version, 'Release v' . $version);
        $this->gitRepository->pushTags('origin');
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.deployed'));

        // Update develop
        $this->gitRepository->checkout('develop');
        $this->gitRepository->pull('origin', 'develop');
        $this->gitRepository->rebase('main');
        $this->gitRepository->forcePushWithLeaseRemote('origin', 'develop');
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.updated_develop'));

        // Cleanup
        if ($this->gitRepository->localBranchExists($currentBranch)) {
            $this->gitRepository->deleteBranch($currentBranch);
        }
        if ($this->gitRepository->remoteBranchExists('origin', $currentBranch)) {
            $this->gitRepository->deleteRemoteBranch('origin', $currentBranch);
        }
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.cleaned'));

        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.success', ['version' => $version]));
    }
}
