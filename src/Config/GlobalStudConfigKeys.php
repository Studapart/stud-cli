<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Canonical YAML key names for ~/.config/stud/config.yml.
 *
 * Agent JSON property names remain in {@see GlobalStudConfigFieldMap}.
 */
final class GlobalStudConfigKeys
{
    public const LANGUAGE = 'LANGUAGE';
    public const GIT_PROVIDERS = 'GIT_PROVIDERS';
    public const WORK_ITEM_PROVIDERS = 'WORK_ITEM_PROVIDERS';
    public const JIRA_URL = 'JIRA_URL';
    public const JIRA_EMAIL = 'JIRA_EMAIL';
    public const JIRA_API_TOKEN = 'JIRA_API_TOKEN';
    public const JIRA_TRANSITION_ENABLED = 'JIRA_TRANSITION_ENABLED';
    public const GITHUB_TOKEN = 'GITHUB_TOKEN';
    public const GITLAB_TOKEN = 'GITLAB_TOKEN';
    public const LINEAR_API_KEY = 'LINEAR_API_KEY';
    public const GIT_TOKEN = 'GIT_TOKEN';
    public const GIT_PROVIDER = 'GIT_PROVIDER';
    public const GITLAB_INSTANCE_URL = 'GITLAB_INSTANCE_URL';
    public const MIGRATION_VERSION = 'migration_version';

    /**
     * @return list<string>
     */
    public static function requiredJiraCredentialKeys(): array
    {
        return [
            self::JIRA_URL,
            self::JIRA_EMAIL,
            self::JIRA_API_TOKEN,
        ];
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public static function hasJiraCredentials(array $globalConfig): bool
    {
        foreach (self::requiredJiraCredentialKeys() as $key) {
            if (! self::hasNonEmptyStringValue($globalConfig, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public static function hasLinearApiKey(array $globalConfig): bool
    {
        return self::hasNonEmptyStringValue($globalConfig, self::LINEAR_API_KEY);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function hasNonEmptyStringValue(array $config, string $key): bool
    {
        if (! isset($config[$key]) || ! is_string($config[$key])) {
            return false;
        }

        return trim($config[$key]) !== '';
    }
}
