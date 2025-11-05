<?php

namespace App\Command;

use App\Git\GitRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'please', aliases: ['pl'], description: 'A power-user, safe force-push (force-with-lease)')]
class PleaseCommand extends Command
{
    public function __construct(private readonly GitRepository $gitRepository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $upstream = $this->gitRepository->getUpstreamBranch();

        if (null === $upstream) {
            $io->error([
                'Your current branch does not have an upstream remote configured.',
                'For the initial push and to create a Pull Request, please use "stud submit".',
            ]);

            return Command::FAILURE;
        }

        $io->warning('⚠️  Forcing with lease...');
        $this->gitRepository->forcePushWithLease();

        return Command::SUCCESS;
    }
}
