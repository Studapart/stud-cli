<?php

declare(strict_types=1);

namespace App\Service\Jira;

/**
 * Repeated Jira JQL literal fragments (protocol vocabulary).
 */
final class JiraJqlFragments
{
    public const ASSIGNEE_CURRENT_USER = 'assignee = currentUser()';

    public const ORDER_BY_UPDATED_DESC = 'ORDER BY updated DESC';
}
