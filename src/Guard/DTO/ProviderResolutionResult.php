<?php

declare(strict_types=1);

namespace App\Guard\DTO;

use App\DTO\MessageRef;

/**
 * Result of unified issue-tracker provider resolution.
 */
final class ProviderResolutionResult
{
    /**
     * @param list<'jira'|'linear'> $providers
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $providers,
        public readonly ?MessageRef $block = null,
        public readonly ?string $inferredFrom = null,
    ) {
    }

    /**
     * @param list<'jira'|'linear'> $providers
     */
    public static function single(array $providers, string $inferredFrom): self
    {
        return new self('single', $providers, null, $inferredFrom);
    }

    /**
     * @param list<'jira'|'linear'> $providers
     */
    public static function dualAggregate(array $providers, string $inferredFrom): self
    {
        return new self('dual_aggregate', $providers, null, $inferredFrom);
    }

    public static function blocked(MessageRef $block): self
    {
        return new self('blocked', [], $block, null);
    }

    public function isBlocked(): bool
    {
        return $this->mode === 'blocked';
    }
}
