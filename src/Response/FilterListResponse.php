<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\Filter;

final class FilterListResponse extends AbstractResponse
{
    /**
     * @param Filter[] $filters
     */
    private function __construct(
        bool $success,
        ?string $error,
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

    public static function error(string $error): self
    {
        return new self(false, $error, []);
    }
}
