<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class FlattenHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly string $baseBranch,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $io->section($this->translator->trans('flatten.section'));

        // 1. Check for clean working directory
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (! empty($gitStatus)) {
            $io->error($this->translator->trans('flatten.error_dirty_working'));

            return 1;
        }

        // 2. Check if there are any fixup commits
        $baseSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        $hasFixups = $this->gitRepository->hasFixupCommits($baseSha);

        if (! $hasFixups) {
            $io->note($this->translator->trans('flatten.no_fixups'));

            return 0;
        }

        // 3. Warn about history rewrite
        $io->warning($this->translator->trans('flatten.warning_rewrite'));

        // 4. Perform the rebase with autosquash
        try {
            $this->gitRepository->rebaseAutosquash($baseSha);
            $io->success($this->translator->trans('flatten.success'));

            return 0;
        } catch (\Exception $e) {
            $io->error($this->translator->trans('flatten.error_rebase', ['error' => $e->getMessage()]));

            return 1;
        }
    }
}
