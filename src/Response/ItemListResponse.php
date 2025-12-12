<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\WorkItem;

final class ItemListResponse extends AbstractResponse
{
    /**
     * @param WorkItem[] $issues
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly array $issues,
        public readonly bool $all,
        public readonly ?string $project
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param WorkItem[] $issues
     */
    public static function success(array $issues, bool $all, ?string $project): self
    {
        return new self(true, null, $issues, $all, $project);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, [], false, null);
    }
}
