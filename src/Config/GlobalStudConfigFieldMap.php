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
        'language' => GlobalStudConfigKeys::LANGUAGE,
        'gitProviders' => GlobalStudConfigKeys::GIT_PROVIDERS,
        'workItemProviders' => GlobalStudConfigKeys::WORK_ITEM_PROVIDERS,
        'jiraUrl' => GlobalStudConfigKeys::JIRA_URL,
        'jiraEmail' => GlobalStudConfigKeys::JIRA_EMAIL,
        'jiraApiToken' => GlobalStudConfigKeys::JIRA_API_TOKEN,
        'jiraTransitionEnabled' => GlobalStudConfigKeys::JIRA_TRANSITION_ENABLED,
        'githubToken' => GlobalStudConfigKeys::GITHUB_TOKEN,
        'gitlabToken' => GlobalStudConfigKeys::GITLAB_TOKEN,
        'linearApiKey' => GlobalStudConfigKeys::LINEAR_API_KEY,
    ];

    /**
     * @return list<string>
     */
    public static function allowedInputKeys(): array
    {
        return array_keys(self::INPUT_TO_YAML);
    }
}
