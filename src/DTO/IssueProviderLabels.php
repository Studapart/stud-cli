<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Per-issue provider slugs for list serialization, shown only when included.
 */
final class IssueProviderLabels
{
    /**
     * @param list<string> $providers
     */
    public function __construct(
        public readonly array $providers,
        public readonly bool $include,
    ) {
    }
}
