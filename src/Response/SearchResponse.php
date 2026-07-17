<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;
use App\DTO\WorkItem;

final class SearchResponse extends AbstractResponse
{
    /**
     * @param WorkItem[] $issues
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly array $issues,
        public readonly string $jql
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param WorkItem[] $issues
     */
    public static function success(array $issues, string $jql): self
    {
        return new self(true, null, $issues, $jql);
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, [], '');
    }
}
