<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\GitProvider;
use App\Enum\IssueTrackerProvider;
use App\Service\GlobalConfigProviderResolver;
use PHPUnit\Framework\TestCase;

class GlobalConfigProviderResolverTest extends TestCase
{
    private GlobalConfigProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new GlobalConfigProviderResolver();
    }

    public function testNormalizeGitProvidersFiltersUnknownAndDedupes(): void
    {
        $this->assertSame(
            [GitProvider::Github->value, GitProvider::Gitlab->value],
            $this->resolver->normalizeGitProviders(['GitHub', 'gitlab', 'gitlab', 'invalid'])
        );
    }

    public function testNormalizeWorkItemProvidersFiltersUnknownAndDedupes(): void
    {
        $this->assertSame(
            [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
            $this->resolver->normalizeIssueTrackerProviders(['JIRA', 'linear', 'nope'])
        );
    }

    public function testInferDefaultGitProvidersFromStoredTokens(): void
    {
        $this->assertSame(
            [GitProvider::Github->value],
            $this->resolver->inferDefaultGitProviders(['GITHUB_TOKEN' => 'gh'])
        );
        $this->assertSame(
            [GitProvider::Gitlab->value],
            $this->resolver->inferDefaultGitProviders(['GITLAB_TOKEN' => 'gl'])
        );
        $this->assertSame(
            [GitProvider::Github->value, GitProvider::Gitlab->value],
            $this->resolver->inferDefaultGitProviders(['GITHUB_TOKEN' => 'gh', 'GITLAB_TOKEN' => 'gl'])
        );
    }

    public function testInferDefaultWorkItemProvidersFromStoredCredentials(): void
    {
        $this->assertSame(
            [IssueTrackerProvider::Jira->value],
            $this->resolver->inferDefaultIssueTrackerProviders(['JIRA_URL' => 'https://jira.example.com'])
        );
        $this->assertSame(
            [IssueTrackerProvider::Linear->value],
            $this->resolver->inferDefaultIssueTrackerProviders(['LINEAR_API_KEY' => 'lin'])
        );
        $this->assertSame(
            [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
            $this->resolver->inferDefaultIssueTrackerProviders([
                'JIRA_URL' => 'https://jira.example.com',
                'LINEAR_API_KEY' => 'lin',
            ])
        );
    }

    public function testCollectsProviderFlags(): void
    {
        $git = [GitProvider::Github->value, GitProvider::Gitlab->value];
        $work = [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value];

        $this->assertTrue($this->resolver->collectsGithub($git));
        $this->assertTrue($this->resolver->collectsGitlab($git));
        $this->assertTrue($this->resolver->collectsJira($work));
        $this->assertTrue($this->resolver->collectsLinear($work));
        $this->assertTrue($this->resolver->collectsIssueTracker(IssueTrackerProvider::Jira, $work));
        $this->assertFalse($this->resolver->collectsIssueTracker(IssueTrackerProvider::Auto, $work));
        $this->assertFalse($this->resolver->collectsGithub([GitProvider::Gitlab->value]));
        $this->assertFalse($this->resolver->collectsLinear([IssueTrackerProvider::Jira->value]));
    }

    public function testResolveGitProvidersPrefersExplicitList(): void
    {
        $this->assertSame(
            [GitProvider::Gitlab->value],
            $this->resolver->resolveGitProviders([
                'GIT_PROVIDERS' => ['gitlab'],
                'GITHUB_TOKEN' => 'gh',
            ])
        );
    }

    public function testInferGitProvidersFromLegacyTokenAndProvider(): void
    {
        $this->assertSame(
            [GitProvider::Gitlab->value],
            $this->resolver->inferGitProvidersFromLegacy([
                'GIT_TOKEN' => 'legacy',
                'GIT_PROVIDER' => 'gitlab',
            ])
        );
    }

    public function testResolveWorkItemProvidersFromCredentials(): void
    {
        $this->assertSame(
            [IssueTrackerProvider::Linear->value],
            $this->resolver->resolveIssueTrackerProviders([
                'LINEAR_API_KEY' => 'lin',
            ])
        );
    }

    public function testResolveWorkItemProvidersDefaultsToJiraWhenNoCredentials(): void
    {
        $this->assertSame(
            [IssueTrackerProvider::Jira->value],
            $this->resolver->resolveIssueTrackerProviders([]),
        );
    }
}
