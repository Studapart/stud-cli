<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Logger service for handling verbosity-aware logging in handlers.
 * Simplifies verbosity level checking and provides consistent color formatting.
 */
class Logger
{
    public const VERBOSITY_NORMAL = 0;
    public const VERBOSITY_VERBOSE = 1;
    public const VERBOSITY_VERY_VERBOSE = 2;
    public const VERBOSITY_DEBUG = 3;

    /**
     * @param array<string, string> $colors Color configuration (for future use in color formatting)
     */
    public function __construct(
        private readonly SymfonyStyle $io,
        /** @phpstan-ignore-next-line - Colors property reserved for future color formatting implementation */
        private readonly array $colors
    ) {
    }

    /**
     * Logs an error message.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string|array<string> $message
     */
    public function error(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->error($message);
        }
    }

    /**
     * Logs a warning message.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string|array<string> $message
     */
    public function warning(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->warning($message);
        }
    }

    /**
     * Logs a note/info message.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string|array<string> $message
     */
    public function note(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->note($message);
        }
    }

    /**
     * Logs a success message.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string|array<string> $message
     */
    public function success(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->success($message);
        }
    }

    /**
     * Logs informational text.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string|array<string> $message
     */
    public function text(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->text($message);
        }
    }

    /**
     * Logs a writeln message with optional color formatting.
     *
     * @param int $verbosity Minimum verbosity level to display
     * @param string $message Message to display (can include color tags)
     */
    public function writeln(int $verbosity, string $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->writeln($message);
        }
    }

    /**
     * Logs a Jira-related informational message with jira_message color.
     *
     * @param int $verbosity Minimum verbosity level to display
     * @param string $message Message to display
     */
    public function jiraWriteln(int $verbosity, string $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $jiraColor = $this->colors['jira_message'] ?? 'blue';
            $this->io->writeln("<fg={$jiraColor}>{$message}</>");
        }
    }

    /**
     * Logs a Git-related informational message with git_message color.
     *
     * @param int $verbosity Minimum verbosity level to display
     * @param string $message Message to display
     */
    public function gitWriteln(int $verbosity, string $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $gitColor = $this->colors['git_message'] ?? 'yellow';
            $this->io->writeln("<fg={$gitColor}>{$message}</>");
        }
    }

    /**
     * Logs Jira-related informational text with jira_message color.
     *
     * @param int $verbosity Minimum verbosity level to display
     * @param string|array<string> $message Message to display
     */
    public function jiraText(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $jiraColor = $this->colors['jira_message'] ?? 'blue';
            if (is_array($message)) {
                $colored = array_map(fn ($line) => "<fg={$jiraColor}>{$line}</>", $message);
                $this->io->text($colored);
            } else {
                $this->io->text("<fg={$jiraColor}>{$message}</>");
            }
        }
    }

    /**
     * Logs Git-related informational text with git_message color.
     *
     * @param int $verbosity Minimum verbosity level to display
     * @param string|array<string> $message Message to display
     */
    public function gitText(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $gitColor = $this->colors['git_message'] ?? 'yellow';
            if (is_array($message)) {
                $colored = array_map(fn ($line) => "<fg={$gitColor}>{$line}</>", $message);
                $this->io->text($colored);
            } else {
                $this->io->text("<fg={$gitColor}>{$message}</>");
            }
        }
    }

    /**
     * Checks if message should be displayed based on current verbosity level.
     * Respects --quiet flag (suppresses all output).
     */
    protected function shouldDisplay(int $verbosity): bool
    {
        // If quiet mode, suppress all output
        if ($this->io->isQuiet()) {
            return false;
        }

        $currentVerbosity = $this->getCurrentVerbosity();

        return $currentVerbosity >= $verbosity;
    }

    /**
     * Gets current verbosity level from SymfonyStyle.
     */
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
