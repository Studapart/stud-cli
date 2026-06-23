<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;

final class ProjectsWorkflowResponse extends AbstractResponse
{
    /**
     * @param list<array<string, mixed>> $stateChanges
     * @param list<ResponseMessage>     $messages
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly array $stateChanges,
        array $messages = [],
    ) {
        parent::__construct($success, $error, $messages);
    }

    /**
     * @param list<array<string, mixed>> $stateChanges
     * @param list<ResponseMessage>     $messages
     */
    public static function success(array $stateChanges, array $messages = []): self
    {
        return new self(true, null, $stateChanges, $messages);
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, []);
    }
}
