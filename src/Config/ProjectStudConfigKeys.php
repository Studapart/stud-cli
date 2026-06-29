<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Canonical YAML key names for .git/stud.config.
 *
 * Agent JSON property names remain in {@see ProjectStudConfigFieldMap}.
 */
final class ProjectStudConfigKeys
{
    public const PROJECT_KEY = 'projectKey';
    public const TRANSITION_ID = 'transitionId';
    public const BASE_BRANCH = 'baseBranch';
    public const GIT_PROVIDER = 'gitProvider';
    public const GITHUB_TOKEN = 'githubToken';
    public const GITLAB_TOKEN = 'gitlabToken';
    public const GITLAB_INSTANCE_URL = 'gitlabInstanceUrl';
    public const JIRA_DEFAULT_PROJECT = 'JIRA_DEFAULT_PROJECT';
    public const CONFLUENCE_DEFAULT_SPACE = 'CONFLUENCE_DEFAULT_SPACE';
    public const WORK_ITEM_PROVIDER = 'workItemProvider';
    public const LINEAR_START_STATE_ID = 'linearStartStateId';
    public const LINEAR_TYPE_LABEL_GROUP_ID = 'linearTypeLabelGroupId';
    public const LINEAR_TYPE_BRANCH_PREFIXES = 'linearTypeBranchPrefixes';
    public const MIGRATION_VERSION = 'migration_version';

    /**
     * @return list<string>
     */
    public static function yamlKeys(): array
    {
        return [
            self::PROJECT_KEY,
            self::TRANSITION_ID,
            self::BASE_BRANCH,
            self::GIT_PROVIDER,
            self::GITHUB_TOKEN,
            self::GITLAB_TOKEN,
            self::GITLAB_INSTANCE_URL,
            self::JIRA_DEFAULT_PROJECT,
            self::CONFLUENCE_DEFAULT_SPACE,
            self::WORK_ITEM_PROVIDER,
            self::LINEAR_START_STATE_ID,
            self::LINEAR_TYPE_LABEL_GROUP_ID,
            self::LINEAR_TYPE_BRANCH_PREFIXES,
            self::MIGRATION_VERSION,
        ];
    }
}
