<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Centralized policy for identifying and redacting secret configuration keys.
 * Used by config:show to display configuration safely for debugging and support.
 */
final class SecretKeyPolicy
{
    public const REDACTED_PLACEHOLDER = '*** REDACTED ***';

    /**
     * Known secret key names (case-sensitive match against config keys).
     *
     * @var list<string>
     */
    private const KNOWN_SECRET_KEYS = [
        'JIRA_API_TOKEN',
        'GITHUB_TOKEN',
        'GITLAB_TOKEN',
    ];

    /**
     * Substrings that indicate a key is secret (case-insensitive).
     * Used as fallback for unknown or future secret keys.
     *
     * @var list<string>
     */
    private const SECRET_KEY_PATTERNS = ['TOKEN', 'PASSWORD', 'SECRET'];

    /**
     * Returns whether a config key should be treated as secret and redacted.
     */
    public static function isSecretKey(string $key): bool
    {
        if (in_array($key, self::KNOWN_SECRET_KEYS, true)) {
            return true;
        }

        $keyUpper = strtoupper($key);
        foreach (self::SECRET_KEY_PATTERNS as $pattern) {
            if (str_contains($keyUpper, strtoupper($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redacts secret values in a flat config array. Nested arrays are not traversed.
     * Also redacts full values that look like URLs with query strings (e.g. Jira URL with token).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function redact(array $config): array
    {
        $result = [];
        foreach ($config as $key => $value) {
            if (self::isSecretKey($key)) {
                $result[$key] = self::REDACTED_PLACEHOLDER;

                continue;
            }

            if (is_string($value) && self::shouldRedactValue($key, $value)) {
                $result[$key] = self::REDACTED_PLACEHOLDER;

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Returns true if a value should be redacted (e.g. URL with token in query string).
     */
    private static function shouldRedactValue(string $key, string $value): bool
    {
        $keyUpper = strtoupper($key);
        if (str_contains($keyUpper, 'URL') && str_contains($value, '?')) {
            return true;
        }

        return false;
    }
}
