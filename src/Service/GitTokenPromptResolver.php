<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Resolves Git token values from interactive prompts for global config:init.
 * Empty prompt reuses the existing dedicated key or legacy GIT_TOKEN / GIT_PROVIDER (same file).
 *
 * Project config:project-init uses {@see tokenFromUserInput} only; it does not merge legacy keys.
 */
final class GitTokenPromptResolver
{
    /**
     * Non-empty trimmed token from the user, or null if they left the prompt blank.
     * When the prompt label is provided, a value equal to that string (after trim) is treated as empty
     * so the UI question is never persisted as a token.
     *
     * @param string|null $promptLabelForGuard Optional translated prompt text to reject as a value
     */
    public function tokenFromUserInput(?string $userInput, ?string $promptLabelForGuard = null): ?string
    {
        if ($userInput === null || trim($userInput) === '') {
            return null;
        }

        $trimmed = trim($userInput);
        if ($promptLabelForGuard !== null && $trimmed === $promptLabelForGuard) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Value to store for GITHUB_TOKEN or GITLAB_TOKEN after config:init prompts.
     *
     * @param array<string, mixed> $existingConfig
     * @param string|null $promptLabelForGuard Optional translated prompt; same as {@see tokenFromUserInput}
     */
    public function resolveForGlobalInit(?string $userInput, string $newKey, array $existingConfig, ?string $promptLabelForGuard = null): ?string
    {
        $fromInput = $this->tokenFromUserInput($userInput, $promptLabelForGuard);
        if ($fromInput !== null) {
            return $fromInput;
        }
        $fromNewKey = $this->existingNonEmptyToken($existingConfig, $newKey);
        if ($fromNewKey !== null) {
            return $fromNewKey;
        }

        return $this->legacyGitTokenForNewKey($existingConfig, $newKey);
    }

    /**
     * @param array<string, mixed> $existingConfig
     */
    private function existingNonEmptyToken(array $existingConfig, string $newKey): ?string
    {
        $existingNew = $existingConfig[$newKey] ?? null;
        if ($existingNew === null || ! is_string($existingNew) || trim($existingNew) === '') {
            return null;
        }

        return trim($existingNew);
    }

    /**
     * @param array<string, mixed> $existingConfig
     */
    private function legacyGitTokenForNewKey(array $existingConfig, string $newKey): ?string
    {
        $legacyToken = $existingConfig['GIT_TOKEN'] ?? null;
        if ($legacyToken === null || ! is_string($legacyToken) || trim($legacyToken) === '') {
            return null;
        }
        $provider = isset($existingConfig['GIT_PROVIDER']) && is_string($existingConfig['GIT_PROVIDER'])
            ? strtolower($existingConfig['GIT_PROVIDER'])
            : null;
        $opposite = $newKey === 'GITHUB_TOKEN' ? 'gitlab' : 'github';

        return ($provider === $opposite) ? null : trim($legacyToken);
    }
}
