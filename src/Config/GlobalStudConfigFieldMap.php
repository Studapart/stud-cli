<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Maps config:init agent JSON inputs to global YAML keys in ~/.config/stud/config.yml.
 *
 * Interactive config:init does not use this allowlist; it exists to keep agent mode strict and explicit.
 */
final class GlobalStudConfigFieldMap
{
    /**
     * Input property name => key as stored in global config.yml.
     *
     * @var array<string, string>
     */
    public const INPUT_TO_YAML = [
        'language' => 'LANGUAGE',
        'gitProviders' => 'GIT_PROVIDERS',
        'workItemProviders' => 'WORK_ITEM_PROVIDERS',
        'jiraUrl' => 'JIRA_URL',
        'jiraEmail' => 'JIRA_EMAIL',
        'jiraApiToken' => 'JIRA_API_TOKEN',
        'jiraTransitionEnabled' => 'JIRA_TRANSITION_ENABLED',
        'githubToken' => 'GITHUB_TOKEN',
        'gitlabToken' => 'GITLAB_TOKEN',
        'linearApiKey' => 'LINEAR_API_KEY',
    ];

    /**
     * @return list<string>
     */
    public static function allowedInputKeys(): array
    {
        return array_keys(self::INPUT_TO_YAML);
    }
}
