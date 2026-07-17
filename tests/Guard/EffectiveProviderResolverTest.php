<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Enum\IssueTrackerProvider;
use App\Guard\Resolver\EffectiveProviderResolver;
use PHPUnit\Framework\TestCase;

class EffectiveProviderResolverTest extends TestCase
{
    private EffectiveProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new EffectiveProviderResolver();
    }

    public function testResolveGitProvidersUsesResolvedProviderOverGlobalList(): void
    {
        $providers = $this->resolver->resolveGitProviders(
            ['GIT_PROVIDERS' => ['github', 'gitlab'], 'GITHUB_TOKEN' => 'gh'],
            ['gitProvider' => 'gitlab'],
            true,
            'github',
        );

        $this->assertSame(['github'], $providers);
    }

    public function testResolveGitProvidersUsesProjectProviderWhenResolvedIsNull(): void
    {
        $providers = $this->resolver->resolveGitProviders(
            ['GIT_PROVIDERS' => ['github', 'gitlab']],
            ['gitProvider' => 'github'],
            true,
            null,
        );

        $this->assertSame(['github'], $providers);
    }

    public function testResolveGitProvidersFallsBackToGlobalWhenNoProjectContext(): void
    {
        $providers = $this->resolver->resolveGitProviders(
            ['GIT_PROVIDERS' => ['github', 'gitlab']],
            null,
            false,
            null,
        );

        $this->assertSame(['github', 'gitlab'], $providers);
    }

    public function testResolveWorkItemProvidersUsesActiveProjectProvider(): void
    {
        $result = $this->resolver->resolveIssueTrackerProviders(
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['issueTrackerProvider' => IssueTrackerProvider::Jira->value],
        );

        $this->assertSame([IssueTrackerProvider::Jira->value], $result['providers']);
        $this->assertFalse($result['ambiguous']);
    }

    public function testResolveWorkItemProvidersMarksAmbiguousWhenAutoHasNoIssueKey(): void
    {
        $result = $this->resolver->resolveIssueTrackerProviders(
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['issueTrackerProvider' => IssueTrackerProvider::Auto->value],
        );

        $this->assertTrue($result['ambiguous']);
        $this->assertSame([IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value], $result['providers']);
    }

    public function testResolveWorkItemProvidersMarksAmbiguousWhenAutoCannotResolve(): void
    {
        $result = $this->resolver->resolveIssueTrackerProviders(
            ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value]],
            ['issueTrackerProvider' => IssueTrackerProvider::Auto->value],
            null,
            false,
        );

        $this->assertTrue($result['ambiguous']);
        $this->assertSame([IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value], $result['providers']);
    }

    public function testResolveWorkItemProvidersUsesOverride(): void
    {
        $result = $this->resolver->resolveIssueTrackerProviders(
            ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value]],
            ['issueTrackerProvider' => IssueTrackerProvider::Auto->value],
            IssueTrackerProvider::Jira->value,
            false,
        );

        $this->assertFalse($result['ambiguous']);
        $this->assertSame([IssueTrackerProvider::Jira->value], $result['providers']);
    }

    public function testResolveWorkItemProvidersWithoutProjectConfigUsesGlobalList(): void
    {
        $result = $this->resolver->resolveIssueTrackerProviders(
            ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            null,
        );

        $this->assertFalse($result['ambiguous']);
        $this->assertSame([IssueTrackerProvider::Linear->value], $result['providers']);
    }

    public function testDualAutoAggregateTreatsMissingProjectProviderAsAuto(): void
    {
        $result = $this->resolver->resolveIssueTrackerProviders(
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['projectKey' => 'SCI'],
            null,
            true,
        );

        $this->assertFalse($result['ambiguous']);
        $this->assertSame([IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value], $result['providers']);
    }

    public function testDualAutoAggregateFalseWhenCredentialsMissing(): void
    {
        $result = $this->resolver->resolveIssueTrackerProviders(
            ['ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear']],
            ['issueTrackerProvider' => IssueTrackerProvider::Auto->value],
            null,
            true,
        );

        $this->assertTrue($result['ambiguous']);
        $this->assertSame(
            [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
            $result['providers'],
        );
    }

    public function testIsDualPmWithCredentialsFalseWhenGlobalListsSingleProvider(): void
    {
        $method = new \ReflectionMethod(EffectiveProviderResolver::class, 'isDualPmWithCredentials');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->resolver, ['ISSUE_TRACKER_PROVIDERS' => ['jira']]);

        $this->assertFalse($result);
    }
}
