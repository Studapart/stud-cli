<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\WorkItem;

final class ItemShowResponse extends AbstractResponse
{
    private function __construct(
        bool $success,
        ?string $error,
        public readonly ?WorkItem $issue
    ) {
        parent::__construct($success, $error);
    }

    public static function success(WorkItem $issue): self
    {
        return new self(true, null, $issue);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, null);
    }
}
