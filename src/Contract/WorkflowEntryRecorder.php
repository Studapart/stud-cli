<?php

declare(strict_types=1);

namespace App\Contract;

use App\DTO\MessageRef;
use App\Enum\WorkflowChannel;
use App\Response\WorkflowResponse;
use Symfony\Component\Console\Helper\TableSeparator;

interface WorkflowEntryRecorder
{
    public const VERBOSITY_NORMAL = 0;
    public const VERBOSITY_VERBOSE = 1;
    public const VERBOSITY_VERY_VERBOSE = 2;
    public const VERBOSITY_DEBUG = 3;

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addError(int $verbosity, MessageRef|string|array $message): void;

    public function addErrorWithDetails(int $verbosity, MessageRef|string $userMessage, string $technicalDetails): void;

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addWarning(int $verbosity, MessageRef|string|array $message): void;

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addNote(int $verbosity, MessageRef|string|array $message): void;

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addSuccess(int $verbosity, MessageRef|string|array $message): void;

    /**
     * @param MessageRef|string|array<MessageRef|string> $message
     */
    public function addText(int $verbosity, MessageRef|string|array $message, WorkflowChannel $channel = WorkflowChannel::Default): void;

    public function addRawValue(string $message): void;

    public function addLine(int $verbosity, MessageRef|string $message, WorkflowChannel $channel = WorkflowChannel::Default): void;

    public function addSection(int $verbosity, MessageRef|string $message): void;

    public function addTitle(int $verbosity, MessageRef|string $message): void;

    /**
     * @param array<string> $elements
     */
    public function addListing(int $verbosity, array $elements): void;

    /**
     * @param string|array<string> $message
     */
    public function addComment(int $verbosity, string|array $message): void;

    /**
     * @param string|array<string> $message
     */
    public function addInfo(int $verbosity, string|array $message): void;

    /**
     * @param string|array<string> $message
     */
    public function addCaution(int $verbosity, string|array $message): void;

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function addTable(int $verbosity, array $headers, array $rows): void;

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function addHorizontalTable(int $verbosity, array $headers, array $rows): void;

    /**
     * @param string|array<string|int, mixed>|TableSeparator ...$list
     */
    public function addDefinitionList(int $verbosity, string|array|TableSeparator ...$list): void;

    public function addNewLine(int $verbosity, int $count = 1): void;

    public function toResponse(int $exitCode): WorkflowResponse;
}
