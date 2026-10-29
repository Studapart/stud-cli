<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Copies global configuration and replaces issue-tracker credentials when the
 * project value is a non-empty string. Other keys stay as they are on the global file.
 */
final class EffectiveIssueTrackerCredentials
{
    /**
     * @param array<string, mixed>      $global
     * @param array<string, mixed>|null $project
     *
     * @return array<string, mixed>
     */
    public function overlay(array $global, ?array $project): array
    {
        if ($project === null) {
            return $global;
        }

        foreach ($this->keys() as $key) {
            $value = $this->qualified($project, $key);
            if ($value !== null) {
                $global[$key] = $value;
            }
        }

        return $global;
    }

    /**
     * @param array<string, mixed> $project
     */
    public function projectSupplies(array $project, string $key): bool
    {
        if (! in_array($key, $this->keys(), true)) {
            return false;
        }

        return $this->qualified($project, $key) !== null;
    }

    /**
     * Trims agent and prompt patches for the four credential fields.
     * An empty result is removed so a blank answer does not clear a stored value.
     *
     * @param array<string, mixed> $patches input-keyed patches
     *
     * @return array<string, mixed>
     */
    public function normalizeInputPatches(array $patches): array
    {
        foreach ($this->inputKeys() as $inputKey => $yamlKey) {
            if (! isset($patches[$inputKey]) || ! is_string($patches[$inputKey])) {
                continue;
            }

            $stored = $this->storedScalar($yamlKey, $patches[$inputKey]);
            if ($stored === null) {
                unset($patches[$inputKey]);

                continue;
            }

            $patches[$inputKey] = $stored;
        }

        return $patches;
    }

    /**
     * @return array<string, string>
     */
    private function inputKeys(): array
    {
        return [
            'jiraUrl' => GlobalStudConfigKeys::JIRA_URL,
            'jiraEmail' => GlobalStudConfigKeys::JIRA_EMAIL,
            'jiraApiToken' => GlobalStudConfigKeys::JIRA_API_TOKEN,
            'linearApiKey' => GlobalStudConfigKeys::LINEAR_API_KEY,
        ];
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        return array_values($this->inputKeys());
    }

    /**
     * @param array<string, mixed> $project
     */
    private function qualified(array $project, string $key): ?string
    {
        if (! array_key_exists($key, $project) || ! is_string($project[$key])) {
            return null;
        }

        return $this->storedScalar($key, $project[$key]);
    }

    private function storedScalar(string $yamlKey, string $raw): ?string
    {
        $value = trim($raw);
        if ($yamlKey === GlobalStudConfigKeys::JIRA_URL) {
            $value = rtrim($value, '/');
        }

        return $value === '' ? null : $value;
    }
}
