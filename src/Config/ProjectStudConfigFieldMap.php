<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Maps config:project-init inputs (CLI flags / agent JSON) to YAML keys in .git/stud.config.
 */
final class ProjectStudConfigFieldMap
{
    /**
     * Input property name => key as stored in .git/stud.config.
     *
     * @var array<string, string>
     */
    public const INPUT_TO_YAML = [
        'projectKey' => 'projectKey',
        'transitionId' => 'transitionId',
        'baseBranch' => 'baseBranch',
        'gitProvider' => 'gitProvider',
        'githubToken' => 'githubToken',
        'gitlabToken' => 'gitlabToken',
        'gitlabInstanceUrl' => 'gitlabInstanceUrl',
        'jiraDefaultProject' => 'JIRA_DEFAULT_PROJECT',
        'confluenceDefaultSpace' => 'CONFLUENCE_DEFAULT_SPACE',
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
        'migration_version',
    ];

    /**
     * @return list<string>
     */
    public static function allowedInputKeys(): array
    {
        return array_merge(array_keys(self::INPUT_TO_YAML), self::AGENT_ONLY_KEYS);
    }
}
