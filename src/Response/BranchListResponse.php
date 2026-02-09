<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\BranchListRow;

final class BranchListResponse extends AbstractResponse
{
    /**
     * @param BranchListRow[] $rows
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly array $rows
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param BranchListRow[] $rows
     */
    public static function success(array $rows): self
    {
        return new self(true, null, $rows);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, []);
    }
}
