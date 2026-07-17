<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Stud-neutral write field names for {@see IssueTrackerPort::create} / {@see IssueTrackerPort::update} input.
 *
 * Aligned with {@see \App\DTO\WorkItem} read vocabulary where applicable.
 */
final class StudIssueKeys
{
    public const PROJECT = 'project';

    public const ISSUE_TYPE = 'issueType';

    public const TITLE = 'title';

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
