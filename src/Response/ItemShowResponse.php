<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\WorkItem;

final class ItemShowResponse extends AbstractResponse
{
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly ?WorkItem $issue
    ) {
        parent::__construct($success, $error);
    }

    public static function success(WorkItem $issue): self
    {
        return new self(true, null, $issue);
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, null);
    }
}
