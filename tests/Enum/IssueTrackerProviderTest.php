<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\IssueTrackerProvider;
use App\Exception\IssueTrackerResolutionException;
use PHPUnit\Framework\TestCase;

class IssueTrackerProviderTest extends TestCase
{
    public function testTryFromNormalizedTrimsAndLowercases(): void
    {
        $this->assertSame(IssueTrackerProvider::Jira, IssueTrackerProvider::tryFromNormalized(' JIRA '));
        $this->assertSame(IssueTrackerProvider::Linear, IssueTrackerProvider::tryFromNormalized('Linear'));
        $this->assertSame(IssueTrackerProvider::Auto, IssueTrackerProvider::tryFromNormalized('auto'));
        $this->assertNull(IssueTrackerProvider::tryFromNormalized('unknown'));
    }

    public function testVendorValuesReturnsJiraAndLinearSlugs(): void
    {
        $this->assertSame(['jira', 'linear'], IssueTrackerProvider::vendorValues());
    }

    public function testProjectConfigValuesIncludesAuto(): void
    {
        $this->assertSame(['jira', 'linear', 'auto'], IssueTrackerProvider::projectConfigValues());
        $this->assertTrue(IssueTrackerProvider::isProjectConfigValue('auto'));
        $this->assertTrue(IssueTrackerProvider::isProjectConfigValue('JIRA'));
        $this->assertFalse(IssueTrackerProvider::isProjectConfigValue('unknown'));
    }

    public function testAutoCaseValue(): void
    {
        $this->assertSame('auto', IssueTrackerProvider::Auto->value);
    }

    public function testFromResolvedMapsJiraAndLinear(): void
    {
        $this->assertSame(IssueTrackerProvider::Jira, IssueTrackerProvider::fromResolved(IssueTrackerProvider::Jira->value));
        $this->assertSame(IssueTrackerProvider::Linear, IssueTrackerProvider::fromResolved(IssueTrackerProvider::Linear->value));
    }

    public function testFromResolvedRejectsAuto(): void
    {
        $this->expectException(IssueTrackerResolutionException::class);
        $this->expectExceptionMessage('issue_tracker_provider.unknown_resolved');

        IssueTrackerProvider::fromResolved('auto');
    }

    public function testIsAutoAndIsVendor(): void
    {
        $this->assertTrue(IssueTrackerProvider::Auto->isAuto());
        $this->assertFalse(IssueTrackerProvider::Jira->isAuto());
        $this->assertTrue(IssueTrackerProvider::Jira->isVendor());
        $this->assertFalse(IssueTrackerProvider::Auto->isVendor());
    }
}
