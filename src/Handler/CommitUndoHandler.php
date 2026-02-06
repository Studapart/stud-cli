<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class CommitUndoHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly Logger $logger,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        try {
            $this->gitRepository->getProjectConfigPath();
        } catch (\RuntimeException $e) {
            $this->logger->error(
                Logger::VERBOSITY_NORMAL,
                explode("\n", $this->translator->trans('commit_undo.error_not_repo'))
            );

            return 1;
        }

        if (! $this->gitRepository->hasAtLeastOneCommit()) {
            $this->logger->error(
                Logger::VERBOSITY_NORMAL,
                explode("\n", $this->translator->trans('commit_undo.error_no_commit'))
            );

            return 1;
        }

        if ($this->gitRepository->isHeadPushed()) {
            $this->logger->warning(
                Logger::VERBOSITY_NORMAL,
                $this->translator->trans('commit_undo.warning_pushed')
            );

            $confirmed = $this->logger->confirm(
                $this->translator->trans('commit_undo.confirm_continue'),
                false
            );

            if (! $confirmed) {
                return 1;
            }
        }

        $this->gitRepository->undoLastCommit();
        $this->logger->success(
            Logger::VERBOSITY_NORMAL,
            $this->translator->trans('commit_undo.success')
        );

        return 0;
    }
}
