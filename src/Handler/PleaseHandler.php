<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class PleaseHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $upstream = $this->gitRepository->getUpstreamBranch();

        if (null === $upstream) {
            $io->error(explode("\n", $this->translator->trans('please.error_no_upstream')));

            return 1;
        }

        $io->warning($this->translator->trans('please.warning_force'));
        $this->gitRepository->forcePushWithLease();

        return 0;
    }
}
