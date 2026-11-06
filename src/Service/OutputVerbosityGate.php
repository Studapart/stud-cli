<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Decides whether a log line is displayed for the current console verbosity, quiet mode, and output suppression.
 */
class OutputVerbosityGate
{
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly bool $suppressOutput = false,
    ) {
    }

    /**
     * Checks if non-essential output should be displayed based on current verbosity level.
     * When output is quiet (--quiet), suppresses informational messages.
     */
    public function allows(int $verbosity): bool
    {
        if ($this->suppressOutput) {
            return false;
        }

        if ($this->io->isQuiet()) {
            return false;
        }

        return $this->getCurrentVerbosity() >= $verbosity;
    }

    /**
     * Checks if essential output (errors, warnings, success, raw values) should be displayed.
     * Essential output is always shown when quiet (--quiet means non-interactive, not suppress output).
     */
    public function allowsEssential(int $verbosity): bool
    {
        if ($this->suppressOutput) {
            return false;
        }

        return $this->io->isQuiet() || $this->allows($verbosity);
    }

    private function getCurrentVerbosity(): int
    {
        if ($this->io->isDebug()) {
            return Logger::VERBOSITY_DEBUG;
        }

        if ($this->io->isVeryVerbose()) {
            return Logger::VERBOSITY_VERY_VERBOSE;
        }

        if ($this->io->isVerbose()) {
            return Logger::VERBOSITY_VERBOSE;
        }

        return Logger::VERBOSITY_NORMAL;
    }
}
