<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\Enum\WorkflowChannel;
use App\Service\Prompt\PromptInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Logger service for handling verbosity-aware logging in handlers.
 * Simplifies verbosity level checking and provides consistent color formatting.
 */
class Logger extends CommandOutputBuffer implements PromptInterface
{
    public const VERBOSITY_NORMAL = OutputVerbosityGate::VERBOSITY_NORMAL;
    public const VERBOSITY_VERBOSE = OutputVerbosityGate::VERBOSITY_VERBOSE;
    public const VERBOSITY_VERY_VERBOSE = OutputVerbosityGate::VERBOSITY_VERY_VERBOSE;
    public const VERBOSITY_DEBUG = OutputVerbosityGate::VERBOSITY_DEBUG;

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly WorkflowChannelColorizer $channelColorizer,
        private readonly OutputVerbosityGate $verbosityGate,
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
    }

    /**
     * Registers color styles with the underlying output (for use by ViewConfig/ResponderHelper).
     * Keeps $io encapsulated while allowing ColorHelper to register styles per ADR-005.
     */
    public function registerStyles(ColorHelper $colorHelper): void
    {
        $colorHelper->registerStyles($this->io);
    }

    /**
     * Logs an error message.
     * Errors are always shown, even when output is quiet (--quiet means non-interactive, not suppress errors).
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function error(int $verbosity, MessageRef|string|array $message): void
    {
        if ($this->verbosityGate->allowsEssential($verbosity)) {
            $this->io->error($this->renderLogMessage($message));
        }
    }

    /**
     * Logs an error with both user-friendly and technical details.
     * Errors are always shown, even when output is quiet (--quiet means non-interactive, not suppress errors).
     *
     * @param int $verbosity Minimum verbosity level to display
     * @param MessageRef|string $userMessage User-friendly translated message
     * @param string $technicalDetails Technical error details (Git output, API response, etc.)
     */
    public function errorWithDetails(int $verbosity, MessageRef|string $userMessage, string $technicalDetails): void
    {
        if ($this->verbosityGate->allowsEssential($verbosity)) {
            $this->io->error((string) $userMessage);
            if (! empty(trim($technicalDetails))) {
                $this->io->text(['', ' Technical details: ' . $technicalDetails]);
            }
        }
    }

    /**
     * Logs a warning message.
     * Warnings are always shown, even when output is quiet (--quiet means non-interactive, not suppress output).
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function warning(int $verbosity, MessageRef|string|array $message): void
    {
        if ($this->verbosityGate->allowsEssential($verbosity)) {
            $this->io->warning($this->renderLogMessage($message));
        }
    }

    /**
     * Logs a note/info message.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function note(int $verbosity, MessageRef|string|array $message): void
    {
        if ($this->verbosityGate->allows($verbosity)) {
            $this->io->note($this->renderLogMessage($message));
        }
    }

    /**
     * Logs a success message.
     * Success is always shown, even when output is quiet (--quiet means non-interactive, not suppress output).
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function success(int $verbosity, MessageRef|string|array $message): void
    {
        if ($this->verbosityGate->allowsEssential($verbosity)) {
            $this->io->success($this->renderLogMessage($message));
        }
    }

    /**
     * Logs informational text.
     *
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function text(int $verbosity, MessageRef|string|array $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        if ($this->verbosityGate->allows($verbosity)) {
            $this->io->text($this->channelColorizer->colorize($channel, $this->renderLogMessage($message)));
        }
    }

    /**
     * Outputs a single raw value line (e.g. script-friendly config value).
     * Always visible even when output is quiet (--quiet), so primary-result output can be used in scripts.
     *
     * @param string $message The raw value to output (one line, no formatting)
     */
    public function rawValue(string $message): void
    {
        if ($this->verbosityGate->allowsEssential(self::VERBOSITY_NORMAL)) {
            $this->io->writeln($message, OutputInterface::VERBOSITY_QUIET | OutputInterface::OUTPUT_RAW);
        }
    }

    /**
     * Logs a writeln message with optional color formatting.
     */
    public function writeln(int $verbosity, MessageRef|string $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        if ($this->verbosityGate->allows($verbosity)) {
            $this->io->writeln($this->channelColorizer->colorize($channel, (string) $message));
        }
    }

    /**
     * Logs a Jira-related informational message with jira_message color.
     */
    public function jiraWriteln(int $verbosity, MessageRef|string $message): void
    {
        $this->writeln($verbosity, $message, WorkflowChannel::Jira);
    }

    /**
     * Logs a Git-related informational message with git_message color.
     */
    public function gitWriteln(int $verbosity, MessageRef|string $message): void
    {
        $this->writeln($verbosity, $message, WorkflowChannel::Git);
    }

    /**
     * Logs Jira-related informational text with jira_message color.
     *
     * @param int $verbosity Minimum verbosity level to display
     * @param string|array<string> $message Message to display
     */
    public function jiraText(int $verbosity, string|array $message): void
    {
        if ($this->verbosityGate->allows($verbosity)) {
            $this->io->text($this->channelColorizer->colorize(WorkflowChannel::Jira, $message));
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
        if ($this->verbosityGate->allows($verbosity)) {
            $this->io->text($this->channelColorizer->colorize(WorkflowChannel::Git, $message));
        }
    }

    /**
     * Displays a section header.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param MessageRef|string $message Section message
     */
    public function section(int $verbosity, MessageRef|string $message): void
    {
        if ($this->verbosityGate->allows($verbosity)) {
            $this->io->section((string) $message);
        }
    }

    /**
     * Displays a title.
     *
     * @param int $verbosity Minimum verbosity level to display (VERBOSITY_NORMAL by default)
     * @param MessageRef|string $message Title message
     */
    public function title(int $verbosity, MessageRef|string $message): void
    {
        if ($this->verbosityGate->allows($verbosity)) {
            $this->io->title((string) $message);
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
        if ($this->verbosityGate->allows($verbosity)) {
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
        if ($this->verbosityGate->allows($verbosity)) {
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
        if ($this->verbosityGate->allows($verbosity)) {
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
        if ($this->verbosityGate->allows($verbosity)) {
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
        if ($this->verbosityGate->allows($verbosity)) {
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
        if ($this->verbosityGate->allows($verbosity)) {
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
        if ($this->verbosityGate->allows($verbosity)) {
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
        if ($this->verbosityGate->allows($verbosity)) {
            $this->io->newLine($count);
        }
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     * @return string|array<string>
     */
    private function renderLogMessage(MessageRef|string|array $message): string|array
    {
        if (is_array($message)) {
            return array_map(fn (MessageRef|string $line): string => $this->messageRenderer?->render($line) ?? (string) $line, $message);
        }

        return $this->messageRenderer?->render($message) ?? (string) $message;
    }

    private function renderLogString(MessageRef|string $message): string
    {
        return $this->messageRenderer?->render($message) ?? (string) $message;
    }

    /**
     * Asks a question to the user (interactive - always displayed regardless of verbosity).
     *
     * @param string $question Question to ask
     * @param string|null $default Default value
     * @param callable|null $validator Validation callback
     * @return string|null User's answer, or null if no input provided and no default value, or if user cancels/interactive mode unavailable
     */
    public function ask(MessageRef|string $question, ?string $default = null, ?callable $validator = null): ?string
    {
        return $this->io->ask($this->renderLogString($question), $default, $validator);
    }

    /**
     * Asks a hidden question to the user (interactive - always displayed regardless of verbosity).
     *
     * @param string $question Question to ask
     * @param callable|null $validator Validation callback
     * @return string|null User's answer, or null if no input provided and no default value, or if user cancels/interactive mode unavailable
     */
    public function askHidden(MessageRef|string $question, ?callable $validator = null): ?string
    {
        return $this->io->askHidden($this->renderLogString($question), $validator);
    }

    /**
     * Asks for confirmation (interactive - always displayed regardless of verbosity).
     *
     * @param string $question Question to ask
     * @param bool $default Default value
     * @return bool User's answer
     */
    public function confirm(MessageRef|string $question, bool $default = true): bool
    {
        return $this->io->confirm($this->renderLogString($question), $default);
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
    public function choice(MessageRef|string $question, array $choices, mixed $default = null, bool $multiSelect = false): mixed
    {
        return $this->io->choice($this->renderLogString($question), $choices, $default, $multiSelect);
    }
}
