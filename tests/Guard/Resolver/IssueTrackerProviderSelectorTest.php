<?php

declare(strict_types=1);

namespace App\Tests\Guard\Resolver;

use App\Enum\IssueTrackerProvider;
use App\Guard\DTO\CommandInvocationContext;
use App\Guard\Resolver\IssueTrackerProviderSelector;
use PHPUnit\Framework\TestCase;

class IssueTrackerProviderSelectorTest extends TestCase
{
    private const DUAL_GLOBAL_CONFIG = [
        'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
        'JIRA_URL' => 'https://example.atlassian.net',
        'JIRA_EMAIL' => 'user@example.com',
        'JIRA_API_TOKEN' => 'token',
        'LINEAR_API_KEY' => 'lin',
    ];

    private const DUAL_PROJECT_CONFIG = [
        'issueTrackerProvider' => 'auto',
        'projectKey' => 'SCI',
        'linearTeamKey' => 'SCIL',
    ];

    public function testSelectUsesUnifiedResolutionForProfiledCommand(): void
    {
        $selection = (new IssueTrackerProviderSelector())->select(
            'items:show',
            self::DUAL_GLOBAL_CONFIG,
            self::DUAL_PROJECT_CONFIG,
            new CommandInvocationContext(issueKey: 'SCIL-99'),
        );

        $this->assertSame(['linear'], $selection['providers']);
        $this->assertFalse($selection['ambiguous']);
        $this->assertNull($selection['block']);
        $this->assertSame('issue_key', $selection['resolution']?->inferredFrom);
    }

    public function testSelectMarksBlockedResolutionAsAmbiguous(): void
    {
        $selection = (new IssueTrackerProviderSelector())->select(
            'items:search',
            self::DUAL_GLOBAL_CONFIG,
            self::DUAL_PROJECT_CONFIG,
            new CommandInvocationContext(),
        );

        $this->assertSame([], $selection['providers']);
        $this->assertTrue($selection['ambiguous']);
        $this->assertSame('issue_tracker_provider.search_requires_explicit_provider', $selection['block']?->key);
        $this->assertTrue($selection['resolution']?->isBlocked());
    }

    public function testSelectFallsBackToEffectiveProvidersForUnprofiledCommand(): void
    {
        $selection = (new IssueTrackerProviderSelector())->select(
            'help',
            self::DUAL_GLOBAL_CONFIG,
            self::DUAL_PROJECT_CONFIG,
            new CommandInvocationContext(providerOverride: IssueTrackerProvider::Linear->value),
        );

        $this->assertSame(['linear'], $selection['providers']);
        $this->assertFalse($selection['ambiguous']);
        $this->assertNull($selection['block']);
        $this->assertNull($selection['resolution']);
    }

    public function testSelectReportsAmbiguityForUnprofiledCommandUnderDualAuto(): void
    {
        $selection = (new IssueTrackerProviderSelector())->select(
            'help',
            self::DUAL_GLOBAL_CONFIG,
            self::DUAL_PROJECT_CONFIG,
            new CommandInvocationContext(),
        );

        $this->assertSame(['jira', 'linear'], $selection['providers']);
        $this->assertTrue($selection['ambiguous']);
        $this->assertNull($selection['resolution']);
    }
}
