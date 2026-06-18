<?php

declare(strict_types=1);

namespace App\Handler\GlobalInit;

use App\Contract\WorkflowEntryRecorder;

/**
 * Shared inputs for global config:init provider credential collectors (ADR-020).
 */
final class GlobalInitPromptContext
{
    /**
     * @param array<string, mixed> $existingConfig
     * @param array<string, mixed> $rawAgentInput
     * @param list<string>        $gitProviders
     * @param list<string>        $workItemProviders
     */
    public function __construct(
        public readonly array $existingConfig,
        public readonly array $rawAgentInput,
        public readonly bool $isAgent,
        public readonly WorkflowEntryRecorder $recorder,
        public readonly array $gitProviders,
        public readonly array $workItemProviders,
    ) {
    }
}
