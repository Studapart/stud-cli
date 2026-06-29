<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Jira-shaped issue create/update field bag keys (handler ↔ {@see IssueTrackerPort} contract).
 */
final class IssueFieldBagKeys
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
