<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\WorkItemProvider;
use PHPUnit\Framework\TestCase;

class WorkItemProviderTest extends TestCase
{
    public function testTryFromNormalizedTrimsAndLowercases(): void
    {
        $this->assertSame(WorkItemProvider::Jira, WorkItemProvider::tryFromNormalized(' JIRA '));
        $this->assertSame(WorkItemProvider::Linear, WorkItemProvider::tryFromNormalized('Linear'));
        $this->assertNull(WorkItemProvider::tryFromNormalized('unknown'));
    }

    public function testValuesReturnsAllProviderSlugs(): void
    {
        $this->assertSame(['jira', 'linear'], WorkItemProvider::values());
    }

    public function testProjectConfigValuesIncludesAuto(): void
    {
        $this->assertSame(['jira', 'linear', 'auto'], WorkItemProvider::projectConfigValues());
        $this->assertTrue(WorkItemProvider::isProjectConfigValue('auto'));
        $this->assertTrue(WorkItemProvider::isProjectConfigValue('JIRA'));
        $this->assertFalse(WorkItemProvider::isProjectConfigValue('unknown'));
    }
}
