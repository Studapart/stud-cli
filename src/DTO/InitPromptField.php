<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * One stud config:init value: its agent-input key, stored value, interactive prompt, and normalizer.
 */
final class InitPromptField
{
    /**
     * @param (\Closure(string): string)|null $normalize
     */
    public function __construct(
        public readonly string $agentKey,
        public readonly mixed $existingStored,
        public readonly MessageRef|string $interactivePrompt,
        public readonly ?\Closure $normalize = null,
    ) {
    }
}
