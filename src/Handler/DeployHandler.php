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
        private readonly string $baseBranch,
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

        // Update base branch
        $baseBranchName = str_replace('origin/', '', $this->baseBranch);
        $this->gitRepository->checkout($baseBranchName);
        $this->gitRepository->pull('origin', $baseBranchName);
        $this->gitRepository->rebase('main');
        $this->gitRepository->forcePushWithLeaseRemote('origin', $baseBranchName);
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.updated_develop'));

        $this->cleanupReleaseBranch($currentBranch);
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.cleaned'));

        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.success', ['version' => $version]));
    }

    private function cleanupReleaseBranch(string $branchName): void
    {
        if ($this->gitRepository->localBranchExists($branchName)) {
            $this->deleteLocalBranch($branchName);
        }

        if ($this->gitRepository->remoteBranchExists('origin', $branchName)) {
            $this->gitRepository->deleteRemoteBranch('origin', $branchName);
        }
    }

    private function deleteLocalBranch(string $branchName): void
    {
        $remoteExists = $this->gitRepository->remoteBranchExists('origin', $branchName);

        try {
            $this->gitRepository->deleteBranch($branchName, $remoteExists);

            return;
        } catch (\Exception $e) {
            if ($remoteExists) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.warning_branch_cleanup', ['branch' => $branchName, 'error' => $e->getMessage()]));

                return;
            }
        }

        try {
            $this->gitRepository->deleteBranchForce($branchName);
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('branches.clean.force_delete_warning', ['branch' => $branchName]));
        } catch (\Exception $forceException) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('deploy.warning_branch_cleanup', ['branch' => $branchName, 'error' => $forceException->getMessage()]));
        }
    }
}
