<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StudHelpCommand extends HelpCommand
{
    protected bool $nativeHelpRequested = false;

    /**
     * Track native command help requests triggered by Symfony's `--help` shortcut.
     */
    public function setCommand(Command $command): void
    {
        $this->nativeHelpRequested = true;

        parent::setCommand($command);
    }

    /**
     * Keep Symfony's native help contract while preserving stud's custom help command.
     */
    protected function configure(): void
    {
        parent::configure();

        $this->getDefinition()->getArgument('command_name')->setDefault(null);
        $this->addOption('agent', null, InputOption::VALUE_NONE, 'JSON input/output mode');
    }

    /**
     * Route native `command --help` to Symfony and explicit `stud help` to stud help.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->nativeHelpRequested && ! $input->getOption('agent')) {
            return parent::execute($input, $output);
        }

        $commandName = $input->getArgument('command_name');

        return $this->runStudHelp(
            is_string($commandName) && $commandName !== '' ? $commandName : null,
            (bool) $input->getOption('agent')
        );
    }

    /**
     * Delegate explicit `stud help` invocations to the existing task implementation.
     *
     * @codeCoverageIgnore
     */
    protected function runStudHelp(?string $commandName, bool $agent): int
    {
        \help($commandName, $agent);

        return Command::SUCCESS;
    }
}
