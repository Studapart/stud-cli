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
     * Logs an error with both user-friendly and technical details.
     *
     * @param int $verbosity Minimum verbosity level to display
     * @param string $userMessage User-friendly translated message
     * @param string $technicalDetails Technical error details (Git output, API response, etc.)
     */
    public function errorWithDetails(int $verbosity, string $userMessage, string $technicalDetails): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->error($userMessage);
            if (! empty(trim($technicalDetails))) {
                $this->io->text(['', ' Technical details: ' . $technicalDetails]);
            }
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
     * Displays a section header.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string $message Section message
     */
    public function section(int $verbosity, string $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->section($message);
        }
    }

    /**
     * Displays a title.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string $message Title message
     */
    public function title(int $verbosity, string $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->title($message);
        }
    }

    /**
     * Displays a listing of elements.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param array<string> $elements Elements to list
     */
    public function listing(int $verbosity, array $elements): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->listing($elements);
        }
    }

    /**
     * Logs a comment message.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string|array<string> $message
     */
    public function comment(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->comment($message);
        }
    }

    /**
     * Logs an info message.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string|array<string> $message
     */
    public function info(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->info($message);
        }
    }

    /**
     * Logs a caution message.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string|array<string> $message
     */
    public function caution(int $verbosity, string|array $message): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->caution($message);
        }
    }

    /**
     * Displays a table.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param array<string> $headers Table headers
     * @param array<array<string>> $rows Table rows
     */
    public function table(int $verbosity, array $headers, array $rows): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->table($headers, $rows);
        }
    }

    /**
     * Displays a horizontal table.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param array<string> $headers Table headers
     * @param array<array<string>> $rows Table rows
     */
    public function horizontalTable(int $verbosity, array $headers, array $rows): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->horizontalTable($headers, $rows);
        }
    }

    /**
     * Displays a definition list.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param string|array<string|int, mixed>|\Symfony\Component\Console\Helper\TableSeparator ...$list Definition list items
     */
    public function definitionList(int $verbosity, string|array|\Symfony\Component\Console\Helper\TableSeparator ...$list): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->definitionList(...$list);
        }
    }

    /**
     * Outputs a new line.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param int $count Number of new lines
     */
    public function newLine(int $verbosity, int $count = 1): void
    {
        if ($this->shouldDisplay($verbosity)) {
            $this->io->newLine($count);
        }
    }

    /**
     * Asks a question to the user (interactive - always displayed regardless of verbosity).
     *
     * @param string $question Question to ask
     * @param string|null $default Default value
     * @param callable|null $validator Validation callback
     * @return string|null User's answer, or null if no input provided and no default value, or if user cancels/interactive mode unavailable
     */
    public function ask(string $question, ?string $default = null, ?callable $validator = null): ?string
    {
        return $this->io->ask($question, $default, $validator);
    }

    /**
     * Asks a hidden question to the user (interactive - always displayed regardless of verbosity).
     *
     * @param string $question Question to ask
     * @param callable|null $validator Validation callback
     * @return string|null User's answer, or null if no input provided and no default value, or if user cancels/interactive mode unavailable
     */
    public function askHidden(string $question, ?callable $validator = null): ?string
    {
        return $this->io->askHidden($question, $validator);
    }

    /**
     * Asks for confirmation (interactive - always displayed regardless of verbosity).
     *
     * @param string $question Question to ask
     * @param bool $default Default value
     * @return bool User's answer
     */
    public function confirm(string $question, bool $default = true): bool
    {
        return $this->io->confirm($question, $default);
    }

    /**
     * Asks the user to select from a list of choices (interactive - always displayed regardless of verbosity).
     *
     * @param string $question Question to ask
     * @param array<string> $choices Available choices
     * @param mixed $default Default value
     * @param bool $multiSelect Whether to allow multiple selections
     * @return mixed User's selection
     */
    public function choice(string $question, array $choices, mixed $default = null, bool $multiSelect = false): mixed
    {
        return $this->io->choice($question, $choices, $default, $multiSelect);
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
