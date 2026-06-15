<?php

declare(strict_types=1);

namespace App\DTO;

use App\Contract\WorkflowEntryRecorder;
use App\Enum\WorkflowChannel;
use App\Response\WorkflowResponse;
use App\Service\WorkflowOutput;
use Symfony\Component\Console\Helper\TableSeparator;

final class WorkflowRecorder implements WorkflowEntryRecorder
{
    /**
     * @var list<WorkflowOutputEntry>
     */
    private array $entries = [];

    /**
     * @var list<ResponseMessage>
     */
    private array $messages = [];

    /**
     * @return list<WorkflowOutputEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
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
    public function addError(int $verbosity, MessageRef|string|array $message): void
    {
        $this->messages[] = ResponseMessage::error($this->stringify($message));
        $this->add('error', $verbosity, $message);
    }

    public function addErrorWithDetails(int $verbosity, MessageRef|string $userMessage, string $technicalDetails): void
    {
        $this->messages[] = ResponseMessage::error($userMessage, $technicalDetails !== '' ? $technicalDetails : null);
        $this->add('errorWithDetails', $verbosity, $userMessage, $technicalDetails);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addWarning(int $verbosity, MessageRef|string|array $message): void
    {
        $this->messages[] = ResponseMessage::warning($this->stringify($message));
        $this->add('warning', $verbosity, $message);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addNote(int $verbosity, MessageRef|string|array $message): void
    {
        $this->messages[] = ResponseMessage::notice($this->stringify($message));
        $this->add('note', $verbosity, $message);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addSuccess(int $verbosity, MessageRef|string|array $message): void
    {
        $this->add('success', $verbosity, $message);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addText(int $verbosity, MessageRef|string|array $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        $this->add('text', $verbosity, $message, channel: $channel);
    }

    public function addRawValue(string $message): void
    {
        $this->add('rawValue', self::VERBOSITY_NORMAL, $message);
    }

    public function addLine(int $verbosity, MessageRef|string $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        $this->add('writeln', $verbosity, $message, channel: $channel);
    }

    public function addSection(int $verbosity, MessageRef|string $message): void
    {
        $this->add('section', $verbosity, $message);
    }

    public function addTitle(int $verbosity, MessageRef|string $message): void
    {
        $this->add('title', $verbosity, $message);
    }

    /**
     * @param array<string> $elements
     */
    public function addListing(int $verbosity, array $elements): void
    {
        $this->entries[] = new WorkflowOutputEntry('listing', $verbosity, elements: $elements);
    }

    /**
     * @param string|array<string> $message
     */
    public function addComment(int $verbosity, string|array $message): void
    {
        $this->add('comment', $verbosity, $message);
    }

    /**
     * @param string|array<string> $message
     */
    public function addInfo(int $verbosity, string|array $message): void
    {
        $this->add('info', $verbosity, $message);
    }

    /**
     * @param string|array<string> $message
     */
    public function addCaution(int $verbosity, string|array $message): void
    {
        $this->add('caution', $verbosity, $message);
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function addTable(int $verbosity, array $headers, array $rows): void
    {
        $this->entries[] = new WorkflowOutputEntry('table', $verbosity, headers: $headers, rows: $rows);
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function addHorizontalTable(int $verbosity, array $headers, array $rows): void
    {
        $this->entries[] = new WorkflowOutputEntry('horizontalTable', $verbosity, headers: $headers, rows: $rows);
    }

    /**
     * @param string|array<string|int, mixed>|TableSeparator ...$list
     */
    public function addDefinitionList(int $verbosity, string|array|TableSeparator ...$list): void
    {
        $this->entries[] = new WorkflowOutputEntry('definitionList', $verbosity, definitionList: $list);
    }

    public function addNewLine(int $verbosity, int $count = 1): void
    {
        $this->entries[] = new WorkflowOutputEntry('newLine', $verbosity, count: $count);
    }

    public function toResponse(int $exitCode): WorkflowResponse
    {
        return WorkflowResponse::fromExitCode($exitCode, $this->entries, $this->messages);
    }

    public function absorb(WorkflowOutput $output): void
    {
        $this->entries = array_merge($this->entries, $output->getEntries());
        $this->messages = array_merge($this->messages, $output->getMessages());
    }

    public function absorbResponse(WorkflowResponse $response): void
    {
        $this->entries = array_merge($this->entries, $response->entries);
        $this->messages = array_merge($this->messages, $response->getMessages());
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    private function add(
        string $type,
        int $verbosity,
        MessageRef|string|array $message,
        ?string $technicalDetails = null,
        WorkflowChannel $channel = WorkflowChannel::Default,
    ): void {
        $this->entries[] = new WorkflowOutputEntry($type, $verbosity, $message, $technicalDetails, channel: $channel);
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
}
