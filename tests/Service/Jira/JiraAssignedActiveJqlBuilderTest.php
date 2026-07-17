<?php

declare(strict_types=1);

namespace App\Tests\Service\Jira;

use App\Enum\JiraStatusCategory;
use App\Service\Jira\JiraAssignedActiveJqlBuilder;
use App\Service\Jira\JiraJqlFragments;
use PHPUnit\Framework\TestCase;

class JiraAssignedActiveJqlBuilderTest extends TestCase
{
    public function testBuildWithoutProjectAndOnlyMine(): void
    {
        $jql = JiraAssignedActiveJqlBuilder::build(null, true);

        $this->assertSame(
            JiraJqlFragments::ASSIGNEE_CURRENT_USER
            . ' AND '
            . JiraStatusCategory::activeListJqlClause()
            . ' '
            . JiraJqlFragments::ORDER_BY_UPDATED_DESC,
            $jql,
        );
    }

    public function testBuildWithProjectAndAllUsers(): void
    {
        $jql = JiraAssignedActiveJqlBuilder::build('sci', false);

        $this->assertSame(
            JiraStatusCategory::activeListJqlClause()
            . ' AND project = SCI '
            . JiraJqlFragments::ORDER_BY_UPDATED_DESC,
            $jql,
        );
    }
}
