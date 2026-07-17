<?php

declare(strict_types=1);

namespace App\Guard\DTO;

use App\DTO\MessageRef;

/**
 * Per-invocation CLI and agent inputs used before the readiness guard runs.
 */
final class CommandInvocationContext
{
    public function __construct(
        public readonly ?string $providerOverride = null,
        public readonly ?MessageRef $providerOverrideError = null,
        public readonly ?string $issueKey = null,
        public readonly ?string $scopeKey = null,
        public readonly ?string $filterName = null,
        public readonly ?string $branchIssueKey = null,
        public readonly ?string $attachmentUrl = null,
    ) {
    }
}
