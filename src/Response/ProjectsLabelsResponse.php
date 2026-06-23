<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;

final class ProjectsLabelsResponse extends AbstractResponse
{
    /**
     * @param list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}> $groups
     * @param list<ResponseMessage>                                                                                  $messages
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly array $groups,
        array $messages = [],
    ) {
        parent::__construct($success, $error, $messages);
    }

    /**
     * @param list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}> $groups
     * @param list<ResponseMessage>                                                                                  $messages
     */
    public static function success(array $groups, array $messages = []): self
    {
        return new self(true, null, $groups, $messages);
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, []);
    }
}
