<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\WorkItem;

final class FilterShowResponse extends AbstractResponse
{
    /**
     * @param WorkItem[] $issues
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly array $issues,
        public readonly string $filterName
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param WorkItem[] $issues
     */
    public static function success(array $issues, string $filterName): self
    {
        return new self(true, null, $issues, $filterName);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, [], '');
    }
}
