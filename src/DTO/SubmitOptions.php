<?php

declare(strict_types=1);

namespace App\DTO;

final class SubmitOptions
{
    /**
     * @param list<ResponseMessage> $flattenDiagnostics Flatten messages copied from the push phase
     */
    public function __construct(
        public readonly bool $draft = false,
        public readonly ?string $labels = null,
        public readonly bool $quiet = false,
        public readonly bool $assignToAuthor = false,
        public readonly bool $alreadyPublished = false,
        public readonly ?bool $rewritten = null,
        public readonly array $flattenDiagnostics = [],
    ) {
    }
}
