<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\Filter;
use App\DTO\MessageRef;

final class FilterListResponse extends AbstractResponse
{
    /**
     * @param Filter[] $filters
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly array $filters
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param Filter[] $filters
     */
    public static function success(array $filters): self
    {
        return new self(true, null, $filters);
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, []);
    }
}
