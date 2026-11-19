<?php

declare(strict_types=1);

namespace App\Handler\GlobalInit;

use App\DTO\InitPromptField;
use App\Handler\InitPromptInputHelper;
use App\Service\GitTokenPromptResolver;
use App\Service\Prompt\PromptInterface;

/**
 * Resolves one GitHub/GitLab token for stud config:init (ADR-020).
 */
class GitProviderTokenResolver
{
    public function __construct(
        private readonly InitPromptInputHelper $inputHelper,
        private readonly GitTokenPromptResolver $gitTokenPromptResolver,
        private readonly PromptInterface $prompt,
    ) {
    }

    /**
     * Inactive provider keeps the trimmed stored token; active provider reads agent input or a hidden prompt,
     * then falls back to stored or legacy GIT_TOKEN via {@see GitTokenPromptResolver::resolveForGlobalInit}.
     */
    public function resolve(bool $active, GlobalInitPromptContext $context, string $configKey, InitPromptField $field): ?string
    {
        if (! $active) {
            return $this->inputHelper->nonEmptyStoredString(is_string($field->existingStored) ? $field->existingStored : null);
        }

        $input = $context->isAgent
            ? ($context->rawAgentInput[$field->agentKey] ?? null)
            : $this->prompt->askHidden($field->interactivePrompt);

        return $this->gitTokenPromptResolver->resolveForGlobalInit(
            is_string($input) ? $input : null,
            $configKey,
            $context->existingConfig,
            (string) $field->interactivePrompt,
        );
    }
}
