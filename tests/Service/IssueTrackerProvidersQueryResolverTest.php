<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\IssueTrackerProvider;
use App\Service\IssueTrackerProvidersQueryResolver;
use PHPUnit\Framework\TestCase;

final class IssueTrackerProvidersQueryResolverTest extends TestCase
{
    private IssueTrackerProvidersQueryResolver $resolver;

    /** @var array<string, mixed> */
    private array $dualGlobal;

    /** @var array<string, mixed> */
    private array $dualProject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new IssueTrackerProvidersQueryResolver();
        $this->dualGlobal = [
            'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
            'LINEAR_API_KEY' => 'lin',
        ];
        $this->dualProject = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCIL',
        ];
    }

    public function testOverrideForcesSingleProvider(): void
    {
        $providers = $this->resolver->resolve($this->dualGlobal, $this->dualProject, IssueTrackerProvider::Linear->value);

        $this->assertSame([IssueTrackerProvider::Linear->value], $providers);
    }

    public function testDualAutoAggregateWithoutScope(): void
    {
        $providers = $this->resolver->resolve($this->dualGlobal, $this->dualProject, null);

        $this->assertSame([IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value], $providers);
    }

    public function testExplicitProjectProviderUsed(): void
    {
        $project = ['issueTrackerProvider' => IssueTrackerProvider::Jira->value, 'projectKey' => 'SCI'];
        $providers = $this->resolver->resolve($this->dualGlobal, $project, null);

        $this->assertSame([IssueTrackerProvider::Jira->value], $providers);
    }

    public function testReturnsEmptyWhenNotDualAndNoExplicit(): void
    {
        $providers = $this->resolver->resolve(
            ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            ['projectKey' => 'SCI'],
            null,
        );

        $this->assertSame([], $providers);
    }

    public function testScopeInfersLinearFromIssueKey(): void
    {
        $providers = $this->resolver->resolve($this->dualGlobal, $this->dualProject, null, 'SCIL');

        $this->assertSame([IssueTrackerProvider::Linear->value], $providers);
    }

    public function testScopeInfersJiraOnly(): void
    {
        $providers = $this->resolver->resolve($this->dualGlobal, $this->dualProject, null, 'SCI');

        $this->assertSame([IssueTrackerProvider::Jira->value], $providers);
    }

    public function testScopeDualAggregateWhenBothPrefixesMatch(): void
    {
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCI',
        ];
        $providers = $this->resolver->resolve($this->dualGlobal, $project, null, 'SCI');

        $this->assertSame([IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value], $providers);
    }

    public function testScopeReturnsEmptyWhenNoMatchAndNotDual(): void
    {
        $providers = $this->resolver->resolve(
            ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            ['projectKey' => 'SCI', 'linearTeamKey' => 'ENG'],
            null,
            'UNKNOWN',
        );

        $this->assertSame([], $providers);
    }

    public function testExplicitLinearProjectReturnsSingleProvider(): void
    {
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Linear->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCIL',
        ];
        $providers = $this->resolver->resolve($this->dualGlobal, $project, null);

        $this->assertSame([IssueTrackerProvider::Linear->value], $providers);
    }
}
