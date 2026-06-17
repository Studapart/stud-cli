<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Service\Prompt\PromptInterface;

/**
 * Reusable visible/hidden prompt guards for stud config:init.
 */
class InitPromptInputHelper
{
    public function __construct(
        private readonly PromptInterface $prompt,
    ) {
    }

    /**
     * Trims stored config values; null or whitespace-only becomes null (no stored value).
     */
    public function nonEmptyStoredString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Null, empty string, or whitespace-only counts as skip (same as empty input after trim).
     */
    public function isSkippedInput(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        return trim($value) === '';
    }

    /**
     * @param callable(string): string $normalizeValue
     */
    public function promptRequiredVisible(MessageRef|string $question, ?string $existingStored, callable $normalizeValue): string
    {
        $existing = $this->nonEmptyStoredString($existingStored);
        while (true) {
            $answer = $this->prompt->ask($question, $existing);
            if ($this->isSkippedInput($answer)) {
                if ($existing !== null) {
                    return $normalizeValue($existing);
                }

                continue;
            }

            $trimmed = trim((string) $answer);
            if ($trimmed === (string) $question) {
                continue;
            }

            $normalized = $normalizeValue($trimmed);
            if ($normalized === '') {
                if ($existing !== null) {
                    return $normalizeValue($existing);
                }

                continue;
            }

            return $normalized;
        }
    }

    public function promptRequiredHiddenToken(MessageRef|string $question, ?string $existingStored): string
    {
        $existing = $this->nonEmptyStoredString($existingStored);
        while (true) {
            $answer = $this->prompt->askHidden($question);
            if ($this->isSkippedInput($answer)) {
                if ($existing !== null) {
                    return $existing;
                }

                continue;
            }

            $trimmed = trim((string) $answer);
            if ($trimmed === (string) $question) {
                continue;
            }

            return $trimmed;
        }
    }

    /**
     * When the provider is inactive, returns trimmed stored value or null.
     * When active, prompts via agent JSON or interactive visible/hidden input.
     *
     * @param array<string, mixed>     $rawAgentInput
     * @param callable(string): string $normalize
     */
    public function resolveWhenActive(
        bool $active,
        bool $isAgent,
        array $rawAgentInput,
        string $agentKey,
        mixed $existingStored,
        MessageRef|string $interactivePrompt,
        bool $hidden = false,
        ?callable $normalize = null,
    ): ?string {
        $normalize ??= static fn (string $s): string => $s;

        if (! $active) {
            return $this->nonEmptyStoredString(is_string($existingStored) ? $existingStored : null);
        }

        if ($isAgent) {
            return $this->promptRequiredAgentString($rawAgentInput, $agentKey, $existingStored, $normalize);
        }

        if ($hidden) {
            return $this->promptRequiredHiddenToken(
                $interactivePrompt,
                is_string($existingStored) ? $existingStored : null,
            );
        }

        return $this->promptRequiredVisible(
            $interactivePrompt,
            is_string($existingStored) ? $existingStored : null,
            $normalize,
        );
    }

    /**
     * @param array<string, mixed> $rawAgentInput
     * @param callable(string): string $normalizeValue
     */
    public function promptRequiredAgentString(array $rawAgentInput, string $key, mixed $existingStored, callable $normalizeValue): string
    {
        $existing = $this->nonEmptyStoredString(is_string($existingStored) ? $existingStored : null);
        $value = $rawAgentInput[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            if ($existing !== null) {
                return $normalizeValue($existing);
            }

            return '';
        }

        $trimmed = trim($value);
        if ($trimmed === $key) {
            if ($existing !== null) {
                return $normalizeValue($existing);
            }

            return '';
        }

        return $normalizeValue($trimmed);
    }
}
