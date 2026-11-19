<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\DTO\ProviderFanOutResult;
use App\DTO\ResponseMessage;

/**
 * Runs one read against every issue-tracker provider a dual-capable command queries.
 *
 * A failure is fatal when only one provider is queried; otherwise that provider is skipped.
 */
class IssueTrackerProviderFanOut
{
    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        private readonly array $globalConfig,
        private readonly array $projectConfig,
        private readonly IssueTrackerProvidersQueryResolver $queryResolver,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(IssueTrackerReadPort): list<T> $fetch
     *
     * @return ProviderFanOutResult<T>|MessageRef
     */
    public function collect(
        ?string $providerOverride,
        \Closure $fetch,
        string $fetchErrorKey,
        bool $warnOnFetchFailure = true,
    ): ProviderFanOutResult|MessageRef {
        $providers = $this->resolveProviders($providerOverride);
        if ($providers instanceof MessageRef) {
            return $providers;
        }

        $single = count($providers) === 1;
        $items = [];
        $itemProviders = [];
        $warnings = [];
        $fetchFailures = [];
        foreach ($providers as $providerSlug) {
            $outcome = $this->fetchFromProvider($providerSlug, $fetch, $fetchErrorKey);
            if ($outcome['ok']) {
                foreach ($outcome['items'] as $item) {
                    $items[] = $item;
                    $itemProviders[] = $providerSlug;
                }

                continue;
            }

            if ($single) {
                return $outcome['error'];
            }
            if ($warnOnFetchFailure || ! $outcome['fetchFailed']) {
                $warnings[] = ResponseMessage::warning($outcome['error']);
            } else {
                $fetchFailures[] = $outcome['error'];
            }
        }

        return new ProviderFanOutResult($items, $itemProviders, $warnings, $fetchFailures);
    }

    /**
     * @template T
     *
     * @param \Closure(IssueTrackerReadPort): list<T> $fetch
     *
     * @return array{ok: true, items: list<T>}|array{ok: false, error: MessageRef, fetchFailed: bool}
     */
    protected function fetchFromProvider(string $providerSlug, \Closure $fetch, string $fetchErrorKey): array
    {
        $resolution = $this->portSupplier->resolveForProvider($providerSlug, $this->globalConfig, $this->projectConfig);
        if (! $resolution['ok']) {
            return ['ok' => false, 'error' => $resolution['error'], 'fetchFailed' => false];
        }

        try {
            return ['ok' => true, 'items' => $fetch($resolution['port'])];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => MessageRef::key($fetchErrorKey, ['error' => $e->getMessage()]),
                'fetchFailed' => true,
            ];
        }
    }

    /**
     * @return list<string>|MessageRef
     */
    protected function resolveProviders(?string $providerOverride): array|MessageRef
    {
        $providers = $this->queryResolver->resolve($this->globalConfig, $this->projectConfig, $providerOverride);
        if ($providers !== []) {
            return $providers;
        }

        $resolution = $this->portSupplier->resolve($this->globalConfig, $this->projectConfig);
        if (! $resolution['ok']) {
            return $resolution['error'];
        }

        return [$resolution['provider']];
    }
}
