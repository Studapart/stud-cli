<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;

final class ItemCreateResponse extends AbstractResponse
{
    /**
     * @param list<string>|null $skippedOptionalFields Human-readable names of optional fields the user requested but were skipped (not supported for project/issue type or invalid format).
     * @param list<string>|null $deferredFields Input keys applied on a follow-up update after create.
     * @param list<string>|null $updateWarnings Human-readable fields that were not applied on that update.
     */
    private function __construct(
        bool $success,
        MessageRef|string|null $error,
        public readonly ?string $key = null,
        public readonly ?string $self = null,
        public readonly ?array $skippedOptionalFields = null,
        public readonly ?array $deferredFields = null,
        public readonly ?array $updateWarnings = null,
    ) {
        parent::__construct($success, $error);
    }

    /**
     * @param list<string> $skippedOptionalFields Optional. When non-empty, the responder will display a note that these fields were skipped.
     * @param list<string> $deferredFields
     * @param list<string> $updateWarnings
     */
    public static function success(
        string $key,
        string $self,
        array $skippedOptionalFields = [],
        array $deferredFields = [],
        array $updateWarnings = [],
    ): self {
        return new self(
            true,
            null,
            $key,
            $self,
            $skippedOptionalFields === [] ? null : $skippedOptionalFields,
            $deferredFields === [] ? null : $deferredFields,
            $updateWarnings === [] ? null : $updateWarnings,
        );
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(false, $error, null, null, null);
    }
}
