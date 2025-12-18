<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class PleaseHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $upstream = $this->gitRepository->getUpstreamBranch();

        if (null === $upstream) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('please.error_no_upstream')));

            return 1;
        }

        $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('please.warning_force'));
        $this->gitRepository->forcePushWithLease();

        return 0;
    }
}
