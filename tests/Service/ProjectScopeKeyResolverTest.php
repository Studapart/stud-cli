<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Service\ProjectScopeKeyResolver;
use PHPUnit\Framework\TestCase;

class ProjectScopeKeyResolverTest extends TestCase
{
    private ProjectScopeKeyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ProjectScopeKeyResolver();
    }

    public function testResolveJiraProjectKeyPrefersProjectKey(): void
    {
        $this->assertSame(
            'SCI',
            $this->resolver->resolveJiraProjectKey([
                'projectKey' => ' sci ',
                'JIRA_DEFAULT_PROJECT' => 'OTHER',
            ]),
        );
    }

    public function testResolveJiraProjectKeyFallsBackToDefaultProject(): void
    {
        $this->assertSame(
            'OTHER',
            $this->resolver->resolveJiraProjectKey(['JIRA_DEFAULT_PROJECT' => 'other']),
        );
    }

    public function testResolveJiraProjectKeyReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->resolver->resolveJiraProjectKey([]));
    }

    public function testResolveLinearTeamKeyPrefersExplicitKey(): void
    {
        $this->assertSame(
            'ENG',
            $this->resolver->resolveLinearTeamKey([
                'projectKey' => 'SCI',
                'linearTeamKey' => 'eng',
            ]),
        );
    }

    public function testResolveLinearTeamKeyFallsBackToJiraProjectKey(): void
    {
        $this->assertSame(
            'SCI',
            $this->resolver->resolveLinearTeamKey(['projectKey' => 'SCI']),
        );
    }

    public function testResolveProviderForDiscoveryScopeReturnsJira(): void
    {
        $result = $this->resolver->resolveProviderForDiscoveryScope('SCI', [
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(IssueTrackerProvider::Jira->value, $result['provider']);
    }

    public function testResolveProviderForDiscoveryScopeReturnsLinear(): void
    {
        $result = $this->resolver->resolveProviderForDiscoveryScope('ENG', [
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(IssueTrackerProvider::Linear->value, $result['provider']);
    }

    public function testResolveProviderForDiscoveryScopeReturnsAmbiguousWhenBothMatch(): void
    {
        $result = $this->resolver->resolveProviderForDiscoveryScope('SCI', [
            'projectKey' => 'SCI',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertInstanceOf(MessageRef::class, $result['error']);
        $this->assertSame('issue_tracker_provider.ambiguous_prefix', $result['error']->key);
    }

    public function testResolveProviderForDiscoveryScopeReturnsUnknownWhenNoMatch(): void
    {
        $result = $this->resolver->resolveProviderForDiscoveryScope('XYZ', [
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('issue_tracker_provider.unknown_prefix', $result['error']->key);
    }

    public function testScopeMatchesJiraReturnsTrueForConfiguredKey(): void
    {
        $this->assertTrue($this->resolver->scopeMatchesJira('sci', ['projectKey' => 'SCI']));
    }

    public function testFormatConfiguredScopeKeysListsDistinctKeys(): void
    {
        $formatted = $this->resolver->formatConfiguredScopeKeys([
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ]);

        $this->assertStringContainsString('SCI (jira)', $formatted);
        $this->assertStringContainsString('ENG (linear)', $formatted);
    }

    public function testFormatConfiguredScopeKeysReturnsNoneWhenEmpty(): void
    {
        $this->assertSame('(none)', $this->resolver->formatConfiguredScopeKeys([]));
    }

    public function testScopeMatchesLinearReturnsFalseWhenNoTeamKeyConfigured(): void
    {
        $this->assertFalse($this->resolver->scopeMatchesLinear('SCI', []));
    }

    public function testFormatConfiguredScopeKeysListsLinearWhenOnlyTeamKeyConfigured(): void
    {
        $formatted = $this->resolver->formatConfiguredScopeKeys(['linearTeamKey' => 'ENG']);

        $this->assertSame('ENG (linear)', $formatted);
    }
}
