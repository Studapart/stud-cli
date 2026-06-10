<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;

final class ItemUpdateResponse extends AbstractResponse
{
    /**
     * @param list<string>|null $skippedOptionalFields
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly ?string $key = null,
        public readonly ?array $skippedOptionalFields = null
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param list<string> $skippedOptionalFields
     */
    public static function success(string $key, array $skippedOptionalFields = []): self
    {
        return new self(true, null, $key, $skippedOptionalFields === [] ? null : $skippedOptionalFields);
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, null, null);
    }
}
