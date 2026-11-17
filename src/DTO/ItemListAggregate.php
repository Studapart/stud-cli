<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Collects work items fetched from one or more providers, de-duplicated by key, with per-provider warnings.
 */
final class ItemListAggregate
{
    /**
     * @var list<WorkItem>
     */
    private array $issues = [];

    /**
     * @var array<string, string>
     */
    private array $providerByKey = [];

    /**
     * @var list<ResponseMessage>
     */
    private array $warnings = [];

    /**
     * Keeps the first occurrence of each key; later duplicates are ignored.
     *
     * @param iterable<WorkItem> $fetched
     */
    public function addIssues(string $provider, iterable $fetched): void
    {
        foreach ($fetched as $issue) {
            if (isset($this->providerByKey[$issue->key])) {
                continue;
            }
            $this->providerByKey[$issue->key] = $provider;
            $this->issues[] = $issue;
        }
    }

    public function addWarning(MessageRef|string $message): void
    {
        $this->warnings[] = ResponseMessage::warning($message);
    }

    /**
     * @param list<WorkItem> $issues a reordering of {@see issues()}
     */
    public function reorder(array $issues): void
    {
        $this->issues = $issues;
    }

    public function allProvidersFailed(): bool
    {
        return $this->issues === [] && $this->warnings !== [];
    }

    public function isMultiProvider(): bool
    {
        return count(array_unique($this->providers())) > 1;
    }

    /**
     * @return list<WorkItem>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<string> provider slug for each entry of {@see issues()}, same order
     */
    public function providers(): array
    {
        return array_map(fn (WorkItem $issue): string => $this->providerByKey[$issue->key], $this->issues);
    }

    /**
     * @return list<ResponseMessage>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
