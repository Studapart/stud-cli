<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Resolves environment facts (git repo, interactive mode) from the console event.
 */
class EnvironmentResolver
{
    public function __construct(
        private readonly bool $hasGitRepository,
    ) {
    }

    public static function fromEvent(ConsoleCommandEvent $event, bool $hasGitRepository): self
    {
        return new self($hasGitRepository);
    }

    public function hasGitRepository(): bool
    {
        return $this->hasGitRepository;
    }

    /**
     * @return array{interactive: bool, quiet: bool, agent: bool}
     */
    public function resolveFlags(InputInterface $input): array
    {
        $isQuiet = $input->hasOption('quiet') && (bool) $input->getOption('quiet');
        $isAgent = $input->hasOption('agent') && (bool) $input->getOption('agent');

        return [
            'interactive' => $input->isInteractive(),
            'quiet' => $isQuiet,
            'agent' => $isAgent,
        ];
    }
}
