<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\DTO\WorkflowOutputEntry;
use App\DTO\WorkflowRecorder;
use App\Enum\WorkflowChannel;
use App\Response\WorkflowResponse;
use App\Service\Prompt\PromptInterface;
use Symfony\Component\Console\Helper\TableSeparator;

class WorkflowOutput implements WorkflowEntryRecorder
{
    public const VERBOSITY_NORMAL = WorkflowEntryRecorder::VERBOSITY_NORMAL;
    public const VERBOSITY_VERBOSE = WorkflowEntryRecorder::VERBOSITY_VERBOSE;
    public const VERBOSITY_VERY_VERBOSE = WorkflowEntryRecorder::VERBOSITY_VERY_VERBOSE;
    public const VERBOSITY_DEBUG = WorkflowEntryRecorder::VERBOSITY_DEBUG;

    private readonly WorkflowRecorder $recorder;

    public function __construct(private readonly PromptInterface $prompt)
    {
        $this->recorder = new WorkflowRecorder();
    }

    /**
     * @return list<WorkflowOutputEntry>
     */
    public function getEntries(): array
    {
        return $this->recorder->getEntries();
    }

    /**
     * @return list<ResponseMessage>
     */
    public function getMessages(): array
    {
        return $this->recorder->getMessages();
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addError(int $verbosity, MessageRef|string|array $message): void
    {
        $this->recorder->addError($verbosity, $message);
    }

    public function addErrorWithDetails(int $verbosity, MessageRef|string $userMessage, string $technicalDetails): void
    {
        $this->recorder->addErrorWithDetails($verbosity, $userMessage, $technicalDetails);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addWarning(int $verbosity, MessageRef|string|array $message): void
    {
        $this->recorder->addWarning($verbosity, $message);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addNote(int $verbosity, MessageRef|string|array $message): void
    {
        $this->recorder->addNote($verbosity, $message);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addSuccess(int $verbosity, MessageRef|string|array $message): void
    {
        $this->recorder->addSuccess($verbosity, $message);
    }

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addText(int $verbosity, MessageRef|string|array $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        $this->recorder->addText($verbosity, $message, $channel);
    }

    public function addRawValue(string $message): void
    {
        $this->recorder->addRawValue($message);
    }

    public function addLine(int $verbosity, MessageRef|string $message, WorkflowChannel $channel = WorkflowChannel::Default): void
    {
        $this->recorder->addLine($verbosity, $message, $channel);
    }

    public function addSection(int $verbosity, MessageRef|string $message): void
    {
        $this->recorder->addSection($verbosity, $message);
    }

    public function addTitle(int $verbosity, MessageRef|string $message): void
    {
        $this->recorder->addTitle($verbosity, $message);
    }

    /**
     * @param array<string> $elements
     */
    public function addListing(int $verbosity, array $elements): void
    {
        $this->recorder->addListing($verbosity, $elements);
    }

    /**
     * @param string|array<string> $message
     */
    public function addComment(int $verbosity, string|array $message): void
    {
        $this->recorder->addComment($verbosity, $message);
    }

    /**
     * @param string|array<string> $message
     */
    public function addInfo(int $verbosity, string|array $message): void
    {
        $this->recorder->addInfo($verbosity, $message);
    }

    /**
     * @param string|array<string> $message
     */
    public function addCaution(int $verbosity, string|array $message): void
    {
        $this->recorder->addCaution($verbosity, $message);
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function addTable(int $verbosity, array $headers, array $rows): void
    {
        $this->recorder->addTable($verbosity, $headers, $rows);
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function addHorizontalTable(int $verbosity, array $headers, array $rows): void
    {
        $this->recorder->addHorizontalTable($verbosity, $headers, $rows);
    }

    /**
     * @param string|array<string|int, mixed>|TableSeparator ...$list
     */
    public function addDefinitionList(int $verbosity, string|array|TableSeparator ...$list): void
    {
        $this->recorder->addDefinitionList($verbosity, ...$list);
    }

    public function addNewLine(int $verbosity, int $count = 1): void
    {
        $this->recorder->addNewLine($verbosity, $count);
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

    public function toResponse(int $exitCode): WorkflowResponse
    {
        return $this->recorder->toResponse($exitCode);
    }
}
