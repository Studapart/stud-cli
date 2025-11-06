<?php

namespace App\Handler;

use App\Service\GitRepository;
use Symfony\Component\Console\Style\SymfonyStyle;

class PleaseHandler
{
    public function __construct(private readonly GitRepository $gitRepository)
    {
    }

    public function handle(SymfonyStyle $io): int
    {
        $upstream = $this->gitRepository->getUpstreamBranch();

        if (null === $upstream) {
            $io->error([
                'Your current branch does not have an upstream remote configured.',
                'For the initial push and to create a Pull Request, please use "stud submit".',
            ]);

            return 1;
        }

        $io->warning('⚠️  Forcing with lease...');
        $this->gitRepository->forcePushWithLease();

        return 0;
    }
}
