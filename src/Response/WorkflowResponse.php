<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\DTO\WorkflowOutputEntry;

final class WorkflowResponse extends AbstractResponse
{
    /**
     * @param list<WorkflowOutputEntry> $entries
     * @param list<ResponseMessage> $messages
     */
    private function __construct(
        public readonly int $exitCode,
        public readonly array $entries = [],
        MessageRef|string|null $error = null,
        array $messages = [],
        public readonly ?int $pullNumber = null,
    ) {
        parent::__construct($exitCode === 0, $error, $messages);
    }

    /**
     * @param list<WorkflowOutputEntry> $entries
     * @param list<ResponseMessage> $messages
     */
    public static function fromExitCode(
        int $exitCode,
        array $entries = [],
        array $messages = [],
        ?int $pullNumber = null,
    ): self {
        $error = null;
        foreach ($messages as $message) {
            if ($message->level === \App\Enum\ResponseMessageLevel::Error) {
                $error = $message->message;

                break;
            }
        }

        return new self($exitCode, $entries, $error, $messages, $pullNumber);
    }

    /**
     * @param list<ResponseMessage> $messages
     */
    public function withAdditionalMessages(array $messages): self
    {
        return new self(
            $this->exitCode,
            $this->entries,
            $this->getErrorMessage(),
            array_merge($this->messages, $messages),
            $this->pullNumber,
        );
    }
}
