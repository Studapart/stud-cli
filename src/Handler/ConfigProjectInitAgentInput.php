<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\ProjectStudConfigFieldMap;
use App\Config\ProjectStudConfigKeys;
use App\Response\ConfigProjectInitResponse;

/**
 * Validates alias-normalized agent JSON for config:project-init and coerces it into input-keyed patches.
 */
class ConfigProjectInitAgentInput
{
    /**
     * @param array<string, mixed> $rawAgentInput
     */
    public function validate(array $rawAgentInput): ?ConfigProjectInitResponse
    {
        $reserved = $this->findReservedAgentKeys($rawAgentInput);
        if ($reserved !== []) {
            return ConfigProjectInitResponse::error('config.project_init.reserved_keys', [
                '%keys%' => implode(', ', $reserved),
            ]);
        }
        $unknown = $this->findUnknownAgentKeys($rawAgentInput);
        if ($unknown !== []) {
            return ConfigProjectInitResponse::error('config.project_init.unknown_keys', [
                '%keys%' => implode(', ', $unknown),
            ]);
        }
        if (! $this->isValidTransitionIdInput($rawAgentInput)) {
            return ConfigProjectInitResponse::error('config.project_init.invalid_transition_id');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rawAgentInput
     * @return array<string, mixed> input-keyed patches
     */
    public function patches(array $rawAgentInput): array
    {
        $out = [];
        foreach (array_keys(ProjectStudConfigFieldMap::INPUT_TO_YAML) as $key) {
            if (! array_key_exists($key, $rawAgentInput)) {
                continue;
            }
            $value = $rawAgentInput[$key];
            if ($value === null) {
                continue;
            }
            $coerced = $this->coerceValue($key, $value);
            if (is_string($coerced) && trim($coerced) === '') {
                continue;
            }
            if (is_array($coerced) && $coerced === []) {
                continue;
            }
            $out[$key] = $coerced;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $rawAgentInput
     * @return list<string>
     */
    protected function findUnknownAgentKeys(array $rawAgentInput): array
    {
        $allowed = ProjectStudConfigFieldMap::allowedInputKeys();
        $unknown = [];
        foreach (array_keys($rawAgentInput) as $key) {
            if (! in_array($key, $allowed, true)) {
                $unknown[] = (string) $key;
            }
        }
        sort($unknown);

        return $unknown;
    }

    /**
     * @param array<string, mixed> $rawAgentInput
     * @return list<string>
     */
    protected function findReservedAgentKeys(array $rawAgentInput): array
    {
        $found = [];
        foreach (ProjectStudConfigFieldMap::RESERVED_YAML_KEYS as $key) {
            if (array_key_exists($key, $rawAgentInput)) {
                $found[] = $key;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @param array<string, mixed> $rawAgentInput
     */
    protected function isValidTransitionIdInput(array $rawAgentInput): bool
    {
        $value = $rawAgentInput['transitionId'] ?? null;
        if ($value === null) {
            return true;
        }
        if (is_int($value) && $value >= 0) {
            return true;
        }

        return is_string($value) && ctype_digit(trim($value));
    }

    protected function coerceValue(string $key, mixed $value): mixed
    {
        if ($key === 'transitionId') {
            return (int) $value;
        }

        if (in_array($key, ['gitProvider', 'issueTrackerProvider'], true) && is_string($value)) {
            return strtolower(trim($value));
        }

        if ($key === 'linearTypeBranchPrefixes' && is_array($value)) {
            return $this->normalizeLinearTypeBranchPrefixes($value);
        }

        if (in_array($key, [ProjectStudConfigKeys::WORK_ITEM_LABELS, ProjectStudConfigKeys::PULL_REQUEST_LABELS], true)) {
            return $this->coerceLabelListValue($value);
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    protected function coerceLabelListValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $label) {
            if (! is_string($label)) {
                return $value;
            }
        }

        return ProjectStudConfigKeys::normalizeLabelList($value);
    }

    /**
     * @param array<mixed, mixed> $prefixes
     * @return array<string, string>
     */
    protected function normalizeLinearTypeBranchPrefixes(array $prefixes): array
    {
        $out = [];
        foreach ($prefixes as $typeLabel => $prefix) {
            if (! is_string($typeLabel) || ! is_string($prefix)) {
                continue;
            }
            $label = trim($typeLabel);
            $normalizedPrefix = trim($prefix);
            if ($label === '' || $normalizedPrefix === '') {
                continue;
            }
            $out[$label] = $normalizedPrefix;
        }

        return $out;
    }
}
