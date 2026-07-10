<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Enum\IssueTrackerProvider;
use App\Guard\Capability\ConfluenceAware;
use App\Guard\Capability\GitHosting\GithubAware;
use App\Guard\Capability\GitHosting\GitlabAware;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Guard\CapabilitySet;
use App\Guard\CommandContext;
use App\Guard\CommandGuard;
use PHPUnit\Framework\TestCase;

class CommandGuardTest extends TestCase
{
    private CommandGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new CommandGuard();
    }

    public function testEmptyCapabilitiesProceeds(): void
    {
        $result = $this->guard->check(CapabilitySet::fromList([]), $this->context([], []));

        $this->assertTrue($result->canProceed);
    }

    public function testJiraKeysRequiredWhenJiraProviderActive(): void
    {
        $capabilities = CapabilitySet::fromList([JiraAware::class]);
        $context = $this->context(
            ['JIRA_URL' => 'https://example.atlassian.net'],
            [],
            issueTrackerProviders: [IssueTrackerProvider::Jira->value],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertContains('JIRA_EMAIL', $result->missingGlobalKeys);
        $this->assertContains('JIRA_API_TOKEN', $result->missingGlobalKeys);
    }

    public function testAllJiraKeysPresentProceeds(): void
    {
        $capabilities = CapabilitySet::fromList([JiraAware::class]);
        $context = $this->context([
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token123',
        ], []);

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testLinearOnlyRequiresLinearApiKey(): void
    {
        $capabilities = CapabilitySet::fromList([LinearAware::class]);
        $context = $this->context(
            ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin-key'],
            [],
            issueTrackerProviders: [IssueTrackerProvider::Linear->value],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testLinearOnlyMissingLinearApiKey(): void
    {
        $capabilities = CapabilitySet::fromList([LinearAware::class]);
        $context = $this->context([], [], issueTrackerProviders: [IssueTrackerProvider::Linear->value]);

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['LINEAR_API_KEY'], $result->missingGlobalKeys);
    }

    public function testProjectBaseBranchRequired(): void
    {
        $capabilities = CapabilitySet::fromList([ProjectBaseBranchAware::class]);
        $context = $this->context([], []);

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['baseBranch'], $result->missingProjectKeys);
    }

    public function testGitRepositoryRequired(): void
    {
        $capabilities = CapabilitySet::fromList([GitRepositoryAware::class]);
        $context = $this->context([], [], hasGitRepository: false);

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['git_repository'], $result->environmentFailures);
    }

    public function testGithubTokenMissingWhenGithubGitHostingAdapterActive(): void
    {
        $capabilities = CapabilitySet::fromList([GithubAware::class]);
        $context = $this->context([], [], gitProviders: ['github']);

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['GITHUB_TOKEN'], $result->missingGlobalKeys);
    }

    public function testGitlabTokenMissingWhenGitlabProviderActive(): void
    {
        $capabilities = CapabilitySet::fromList([GitlabAware::class]);
        $context = $this->context([], [], gitProviders: ['gitlab']);

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['GITLAB_TOKEN'], $result->missingGlobalKeys);
    }

    public function testGithubTokenFromProjectConfig(): void
    {
        $capabilities = CapabilitySet::fromList([GithubAware::class]);
        $context = $this->context([], ['githubToken' => 'gh-token'], gitProviders: ['github']);

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testGitlabTokenFromGlobalConfig(): void
    {
        $capabilities = CapabilitySet::fromList([GitlabAware::class]);
        $context = $this->context(['GITLAB_TOKEN' => 'gl-token'], [], gitProviders: ['gitlab']);

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testDualGitProvidersRequireBothTokensWhenNoEffectiveProvider(): void
    {
        $capabilities = CapabilitySet::fromList([
            GithubAware::class,
            GitlabAware::class,
            ProjectBaseBranchAware::class,
        ]);
        $context = $this->context(
            ['GITHUB_TOKEN' => 'gh-token'],
            ['baseBranch' => 'develop'],
            gitProviders: ['github', 'gitlab'],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['GITLAB_TOKEN'], $result->missingGlobalKeys);
    }

    public function testEffectiveGithubGitHostingAdapterIgnoresMissingGitlabToken(): void
    {
        $capabilities = CapabilitySet::fromList([
            GithubAware::class,
            GitlabAware::class,
            ProjectBaseBranchAware::class,
        ]);
        $context = $this->context(
            ['GITHUB_TOKEN' => 'gh-token'],
            ['baseBranch' => 'develop', 'gitProvider' => 'github'],
            gitProviders: ['github'],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testDualGitProvidersRequireBothTokensWhenBothEffective(): void
    {
        $capabilities = CapabilitySet::fromList([
            GithubAware::class,
            GitlabAware::class,
            ProjectBaseBranchAware::class,
        ]);
        $context = $this->context(
            ['GITHUB_TOKEN' => 'gh-token', 'GITLAB_TOKEN' => 'gl-token'],
            ['baseBranch' => 'develop'],
            gitProviders: ['github', 'gitlab'],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testGithubTokenAcceptedFromLegacyGitToken(): void
    {
        $capabilities = CapabilitySet::fromList([GithubAware::class]);
        $context = $this->context(
            ['GIT_TOKEN' => 'legacy-token', 'GIT_PROVIDER' => 'github'],
            [],
            gitProviders: ['github'],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testGitlabTokenAcceptedFromLegacyGitToken(): void
    {
        $capabilities = CapabilitySet::fromList([GitlabAware::class]);
        $context = $this->context(
            ['GIT_TOKEN' => 'legacy-token', 'GIT_PROVIDER' => 'gitlab'],
            [],
            gitProviders: ['gitlab'],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testLegacyTokenRejectedWhenGitProviderMissing(): void
    {
        $capabilities = CapabilitySet::fromList([GithubAware::class]);
        $context = $this->context(
            ['GIT_TOKEN' => 'legacy-token'],
            [],
            gitProviders: ['github'],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['GITHUB_TOKEN'], $result->missingGlobalKeys);
    }

    public function testAmbiguousWorkItemProviderRequiresProjectSelection(): void
    {
        $capabilities = CapabilitySet::fromList([JiraAware::class, LinearAware::class]);
        $context = new CommandContext(
            globalConfig: [
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            projectConfig: ['issueTrackerProvider' => IssueTrackerProvider::Auto->value],
            hasGitRepository: true,
            issueTrackerProviders: [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
            gitProviders: ['github'],
            isInteractive: true,
            isQuiet: false,
            isAgent: false,
            issueTrackerProviderAmbiguous: true,
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['issueTrackerProvider'], $result->missingProjectKeys);
        $this->assertTrue($result->ambiguousIssueTrackerProvider);
        $this->assertSame([], $result->missingGlobalKeys);
    }

    public function testProviderOverrideSkipsAmbiguousProjectPrompt(): void
    {
        $capabilities = CapabilitySet::fromList([JiraAware::class]);
        $context = new CommandContext(
            globalConfig: [
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            projectConfig: ['issueTrackerProvider' => IssueTrackerProvider::Auto->value],
            hasGitRepository: true,
            issueTrackerProviders: [IssueTrackerProvider::Linear->value],
            gitProviders: ['github'],
            isInteractive: true,
            isQuiet: false,
            isAgent: false,
            issueTrackerProviderAmbiguous: false,
            issueTrackerProviderOverride: IssueTrackerProvider::Linear->value,
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
        $this->assertSame([], $result->missingProjectKeys);
    }

    public function testInvalidProviderOverrideBlocks(): void
    {
        $capabilities = CapabilitySet::fromList([JiraAware::class]);
        $context = new CommandContext(
            globalConfig: [],
            projectConfig: [],
            hasGitRepository: true,
            issueTrackerProviders: [],
            gitProviders: ['github'],
            isInteractive: false,
            isQuiet: false,
            isAgent: true,
            providerOverrideError: \App\DTO\MessageRef::key('issue_tracker_provider.invalid_override', ['%value%' => 'auto']),
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertNotNull($result->providerOverrideError);
    }

    public function testConfluenceRequiresJiraCredentials(): void
    {
        $capabilities = CapabilitySet::fromList([ConfluenceAware::class]);
        $context = $this->context([], []);

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertContains('JIRA_URL', $result->missingGlobalKeys);
    }

    public function testEmptyValuesTreatedAsMissing(): void
    {
        $capabilities = CapabilitySet::fromList([JiraAware::class]);
        $context = $this->context([
            'JIRA_URL' => '',
            'JIRA_EMAIL' => '   ',
            'JIRA_API_TOKEN' => null,
        ], []);

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertNotEmpty($result->missingGlobalKeys);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     * @param list<string> $issueTrackerProviders
     * @param list<string> $gitProviders
     */
    private function context(
        array $globalConfig,
        array $projectConfig,
        bool $hasGitRepository = true,
        array $issueTrackerProviders = ['jira'],
        array $gitProviders = ['github'],
    ): CommandContext {
        return new CommandContext(
            globalConfig: $globalConfig,
            projectConfig: $projectConfig,
            hasGitRepository: $hasGitRepository,
            issueTrackerProviders: $issueTrackerProviders,
            gitProviders: $gitProviders,
            isInteractive: true,
            isQuiet: false,
            isAgent: false,
        );
    }
}
