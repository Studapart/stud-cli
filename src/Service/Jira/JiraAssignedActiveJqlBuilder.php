<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Enum\JiraStatusCategory;

final class JiraAssignedActiveJqlBuilder
{
    public static function build(?string $projectKey, bool $onlyMine): string
    {
        $parts = [];
        if ($onlyMine) {
            $parts[] = JiraJqlFragments::ASSIGNEE_CURRENT_USER;
        }
        $parts[] = JiraStatusCategory::activeListJqlClause();
        if ($projectKey !== null && $projectKey !== '') {
            $parts[] = 'project = ' . strtoupper($projectKey);
        }

        return implode(' AND ', $parts) . ' ' . JiraJqlFragments::ORDER_BY_UPDATED_DESC;
    }
}
