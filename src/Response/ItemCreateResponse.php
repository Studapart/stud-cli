<?php

declare(strict_types=1);

namespace App\Response;

final class ItemCreateResponse extends AbstractResponse
{
    /**
     * @param list<string>|null $skippedOptionalFields Human-readable names of optional fields the user requested but were skipped (not supported for project/issue type or invalid format).
     */
    private function __construct(
        bool $success,
        ?string $error,
        public readonly ?string $key = null,
        public readonly ?string $self = null,
        public readonly ?array $skippedOptionalFields = null
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param list<string> $skippedOptionalFields Optional. When non-empty, the responder will display a note that these fields were skipped.
     */
    public static function success(string $key, string $self, array $skippedOptionalFields = []): self
    {
        return new self(true, null, $key, $self, $skippedOptionalFields === [] ? null : $skippedOptionalFields);
    }

    public static function error(string $error): self
    {
        return new self(false, $error, null, null, null);
    }
}
