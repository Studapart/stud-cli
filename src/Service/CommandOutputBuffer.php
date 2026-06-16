<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Enum\WorkflowChannel;
use App\Service\Prompt\PromptInterface;
use Symfony\Component\Console\Helper\TableSeparator;

class CommandOutputBuffer extends WorkflowOutput
{
    public const VERBOSITY_NORMAL = Logger::VERBOSITY_NORMAL;
    public const VERBOSITY_VERBOSE = Logger::VERBOSITY_VERBOSE;
    public const VERBOSITY_VERY_VERBOSE = Logger::VERBOSITY_VERY_VERBOSE;
    public const VERBOSITY_DEBUG = Logger::VERBOSITY_DEBUG;

    /**
     * @var list<ResponseMessage>
     */
    private array $messages = [];

    public function __construct(
        private readonly ?Logger $logger,
        private readonly PromptInterface $prompt,
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
        parent::__construct($prompt);
    }

    /**
     * @return list<ResponseMessage>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function error(int $verbosity, MessageRef|string|array $message): void
    {
        $this->messages[] = ResponseMessage::error($this->stringify($message));
        $this->logger?->error($verbosity, $this->renderForLogger($message));
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addError(int $verbosity, MessageRef|string|array $message): void
    {
        $this->error($verbosity, $message);
    }

    public function errorWithDetails(int $verbosity, MessageRef|string $userMessage, string $technicalDetails): void
    {
        $this->messages[] = ResponseMessage::error($userMessage, $technicalDetails !== '' ? $technicalDetails : null);
        $this->logger?->errorWithDetails($verbosity, $this->renderStringForLogger($userMessage), $technicalDetails);
    }

    public function addErrorWithDetails(int $verbosity, MessageRef|string $userMessage, string $technicalDetails): void
    {
        $this->errorWithDetails($verbosity, $userMessage, $technicalDetails);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function warning(int $verbosity, MessageRef|string|array $message): void
    {
        $this->messages[] = ResponseMessage::warning($this->stringify($message));
        $this->logger?->warning($verbosity, $this->renderForLogger($message));
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addWarning(int $verbosity, MessageRef|string|array $message): void
    {
        $this->warning($verbosity, $message);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function note(int $verbosity, MessageRef|string|array $message): void
    {
        $this->messages[] = ResponseMessage::notice($this->stringify($message));
        $this->logger?->note($verbosity, $this->renderForLogger($message));
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addNote(int $verbosity, MessageRef|string|array $message): void
    {
        $this->note($verbosity, $message);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function success(int $verbosity, MessageRef|string|array $message): void
    {
        $this->logger?->success($verbosity, $this->renderForLogger($message));
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addSuccess(int $verbosity, MessageRef|string|array $message): void
    {
        $this->success($verbosity, $message);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function text(int $verbosity, MessageRef|string|array $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        $rendered = $this->renderForLogger($message);
        match ($channel) {
            WorkflowChannel::Jira => $this->logger?->jiraText($verbosity, $rendered),
            WorkflowChannel::Git => $this->logger?->gitText($verbosity, $rendered),
            WorkflowChannel::Default => $this->logger?->text($verbosity, $rendered),
        };
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addText(int $verbosity, MessageRef|string|array $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        $this->text($verbosity, $message, $channel);
    }

    public function rawValue(string $message): void
    {
        $this->logger?->rawValue($message);
    }

    public function addRawValue(string $message): void
    {
        $this->rawValue($message);
    }

    public function writeln(int $verbosity, MessageRef|string $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        $rendered = $this->renderStringForLogger($message);
        match ($channel) {
            WorkflowChannel::Jira => $this->logger?->jiraWriteln($verbosity, $rendered),
            WorkflowChannel::Git => $this->logger?->gitWriteln($verbosity, $rendered),
            WorkflowChannel::Default => $this->logger?->writeln($verbosity, $rendered),
        };
    }

    public function addLine(int $verbosity, MessageRef|string $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        $this->writeln($verbosity, $message, $channel);
    }

    public function section(int $verbosity, MessageRef|string $message): void
    {
        $this->logger?->section($verbosity, $this->renderStringForLogger($message));
    }

    public function addSection(int $verbosity, MessageRef|string $message): void
    {
        $this->section($verbosity, $message);
    }

    public function title(int $verbosity, MessageRef|string $message): void
    {
        $this->logger?->title($verbosity, $this->renderStringForLogger($message));
    }

    public function addTitle(int $verbosity, MessageRef|string $message): void
    {
        $this->title($verbosity, $message);
    }

    /**
     * @param array<string> $elements
     */
    public function listing(int $verbosity, array $elements): void
    {
        $this->logger?->listing($verbosity, $elements);
    }

    /**
     * @param array<string> $elements
     */
    public function addListing(int $verbosity, array $elements): void
    {
        $this->listing($verbosity, $elements);
    }

    /**
     * @param string|array<string> $message
     */
    public function comment(int $verbosity, string|array $message): void
    {
        $this->logger?->comment($verbosity, $message);
    }

    /**
     * @param string|array<string> $message
     */
    public function addComment(int $verbosity, string|array $message): void
    {
        $this->comment($verbosity, $message);
    }

    /**
     * @param string|array<string> $message
     */
    public function info(int $verbosity, string|array $message): void
    {
        $this->logger?->info($verbosity, $message);
    }

    /**
     * @param string|array<string> $message
     */
    public function addInfo(int $verbosity, string|array $message): void
    {
        $this->info($verbosity, $message);
    }

    /**
     * @param string|array<string> $message
     */
    public function caution(int $verbosity, string|array $message): void
    {
        $this->logger?->caution($verbosity, $message);
    }

    /**
     * @param string|array<string> $message
     */
    public function addCaution(int $verbosity, string|array $message): void
    {
        $this->caution($verbosity, $message);
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function table(int $verbosity, array $headers, array $rows): void
    {
        $this->logger?->table($verbosity, $headers, $rows);
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function addTable(int $verbosity, array $headers, array $rows): void
    {
        $this->table($verbosity, $headers, $rows);
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function horizontalTable(int $verbosity, array $headers, array $rows): void
    {
        $this->logger?->horizontalTable($verbosity, $headers, $rows);
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function addHorizontalTable(int $verbosity, array $headers, array $rows): void
    {
        $this->horizontalTable($verbosity, $headers, $rows);
    }

    /**
     * @param string|array<string|int, mixed>|TableSeparator ...$list
     */
    public function definitionList(int $verbosity, string|array|TableSeparator ...$list): void
    {
        $this->logger?->definitionList($verbosity, ...$list);
    }

    /**
     * @param string|array<string|int, mixed>|TableSeparator ...$list
     */
    public function addDefinitionList(int $verbosity, string|array|TableSeparator ...$list): void
    {
        $this->definitionList($verbosity, ...$list);
    }

    public function newLine(int $verbosity, int $count = 1): void
    {
        $this->logger?->newLine($verbosity, $count);
    }

    public function addNewLine(int $verbosity, int $count = 1): void
    {
        $this->newLine($verbosity, $count);
    }

    public function ask(MessageRef|string $question, ?string $default = null, ?callable $validator = null): ?string
    {
        return $this->prompt->ask($question, $default, $validator);
    }

    public function askHidden(MessageRef|string $question, ?callable $validator = null): ?string
    {
        return $this->prompt->askHidden($question, $validator);
    }

    public function confirm(MessageRef|string $question, bool $default = true): bool
    {
        return $this->prompt->confirm($question, $default);
    }

    /**
     * @param array<string> $choices
     */
    public function choice(MessageRef|string $question, array $choices, mixed $default = null, bool $multiSelect = false): mixed
    {
        return $this->prompt->choice($question, $choices, $default, $multiSelect);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    private function stringify(MessageRef|string|array $message): MessageRef|string
    {
        if (! is_array($message)) {
            return $message;
        }

        return implode("\n", array_map(static fn (MessageRef|string $line): string => (string) $line, $message));
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     * @return string|array<string>
     */
    private function renderForLogger(MessageRef|string|array $message): string|array
    {
        if (is_array($message)) {
            return array_map(fn (MessageRef|string $line): string => $this->messageRenderer?->render($line) ?? (string) $line, $message);
        }

        return $this->messageRenderer?->render($message) ?? (string) $message;
    }

    private function renderStringForLogger(MessageRef|string $message): string
    {
        return $this->messageRenderer?->render($message) ?? (string) $message;
    }
}
