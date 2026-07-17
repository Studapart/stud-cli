<?php

declare(strict_types=1);

namespace App\Service\Jira;

/**
 * Jira REST create/update field names (protocol vocabulary for {@see \App\Service\JiraApiClient}).
 */
final class JiraIssueFieldKeys
{
    public const PROJECT = 'project';

    public const ISSUE_TYPE = 'issuetype';

    public const SUMMARY = 'summary';

    public const DESCRIPTION = 'description';

    public const PARENT = 'parent';

    public const LABELS = 'labels';

    public const PRIORITY = 'priority';

    public const REPORTER = 'reporter';

    public const ASSIGNEE = 'assignee';

    public const KEY = 'key';

    public const ID = 'id';

    public const NAME = 'name';

    public const ACCOUNT_ID = 'accountId';
}
