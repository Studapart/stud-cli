<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\JiraStatusCategory;
use PHPUnit\Framework\TestCase;

class JiraStatusCategoryTest extends TestCase
{
    public function testActiveListJqlClause(): void
    {
        $this->assertSame(
            "statusCategory in ('To Do', 'In Progress')",
            JiraStatusCategory::activeListJqlClause(),
        );
    }

    public function testNotDoneJqlClause(): void
    {
        $this->assertSame('statusCategory != Done', JiraStatusCategory::notDoneJqlClause());
    }
}
