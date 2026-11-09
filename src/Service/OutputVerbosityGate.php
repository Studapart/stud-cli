<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Decides whether a log line is displayed for the current console verbosity, quiet mode, and output suppression.
 */
class OutputVerbosityGate
{
    public const VERBOSITY_NORMAL = 0;
    public const VERBOSITY_VERBOSE = 1;
    public const VERBOSITY_VERY_VERBOSE = 2;
    public const VERBOSITY_DEBUG = 3;

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

    protected function getCurrentVerbosity(): int
    {
        if ($this->io->isDebug()) {
            return self::VERBOSITY_DEBUG;
        }

        if ($this->io->isVeryVerbose()) {
            return self::VERBOSITY_VERY_VERBOSE;
        }

        if ($this->io->isVerbose()) {
            return self::VERBOSITY_VERBOSE;
        }

        return self::VERBOSITY_NORMAL;
    }
}
