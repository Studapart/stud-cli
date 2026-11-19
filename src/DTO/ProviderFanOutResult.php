<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Items collected from every queried issue-tracker provider, with the provider slug of each item.
 *
 * @template T
 */
final class ProviderFanOutResult
{
    /**
     * @param list<T> $items
     * @param list<string> $providers Provider slug for each item, in the same order as $items
     * @param list<ResponseMessage> $warnings
     * @param list<MessageRef> $fetchFailures Fetch errors kept off $warnings when the caller asked for a silent skip
     */
    public function __construct(
        public readonly array $items,
        public readonly array $providers,
        public readonly array $warnings,
        public readonly array $fetchFailures = [],
    ) {
    }

    public function isMultiProvider(): bool
    {
        return count(array_unique($this->providers)) > 1;
    }
}
