<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Maps config:project-init agent JSON inputs to YAML keys in .git/stud.config.
 */
final class ProjectStudConfigFieldMap
{
    /**
     * Input property name => key as stored in .git/stud.config.
     *
     * @var array<string, string>
     */
    public const INPUT_TO_YAML = [
        'projectKey' => ProjectStudConfigKeys::PROJECT_KEY,
        'transitionId' => ProjectStudConfigKeys::TRANSITION_ID,
        'baseBranch' => ProjectStudConfigKeys::BASE_BRANCH,
        'gitProvider' => ProjectStudConfigKeys::GIT_PROVIDER,
        'githubToken' => ProjectStudConfigKeys::GITHUB_TOKEN,
        'gitlabToken' => ProjectStudConfigKeys::GITLAB_TOKEN,
        'gitlabInstanceUrl' => ProjectStudConfigKeys::GITLAB_INSTANCE_URL,
        'jiraDefaultProject' => ProjectStudConfigKeys::JIRA_DEFAULT_PROJECT,
        'confluenceDefaultSpace' => ProjectStudConfigKeys::CONFLUENCE_DEFAULT_SPACE,
        'workItemProvider' => ProjectStudConfigKeys::WORK_ITEM_PROVIDER,
        'linearStartStateId' => ProjectStudConfigKeys::LINEAR_START_STATE_ID,
        'linearTypeLabelGroupId' => ProjectStudConfigKeys::LINEAR_TYPE_LABEL_GROUP_ID,
        'linearTypeBranchPrefixes' => ProjectStudConfigKeys::LINEAR_TYPE_BRANCH_PREFIXES,
    ];

    /**
     * Agent JSON keys that are not written to the project file.
     *
     * @var list<string>
     */
    public const AGENT_ONLY_KEYS = [
        'skipBaseBranchRemoteCheck',
    ];

    /**
     * Keys that must never be set via this command (managed by migrations / tooling).
     *
     * @var list<string>
     */
    public const RESERVED_YAML_KEYS = [
        ProjectStudConfigKeys::MIGRATION_VERSION,
    ];

    /**
     * @return list<string>
     */
    public static function allowedInputKeys(): array
    {
        return array_merge(array_keys(self::INPUT_TO_YAML), self::AGENT_ONLY_KEYS);
    }
}
