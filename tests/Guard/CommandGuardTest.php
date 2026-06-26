<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Guard\Capability\ConfluenceAware;
use App\Guard\Capability\GitProviderGithubAware;
use App\Guard\Capability\GitProviderGitlabAware;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\Capability\WorkItemLinearAware;
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
        $capabilities = CapabilitySet::fromList([WorkItemJiraAware::class]);
        $context = $this->context(
            ['JIRA_URL' => 'https://example.atlassian.net'],
            [],
            workItemProviders: ['jira'],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertContains('JIRA_EMAIL', $result->missingGlobalKeys);
        $this->assertContains('JIRA_API_TOKEN', $result->missingGlobalKeys);
    }

    public function testAllJiraKeysPresentProceeds(): void
    {
        $capabilities = CapabilitySet::fromList([WorkItemJiraAware::class]);
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
        $capabilities = CapabilitySet::fromList([WorkItemLinearAware::class]);
        $context = $this->context(
            ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin-key'],
            [],
            workItemProviders: ['linear'],
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testLinearOnlyMissingLinearApiKey(): void
    {
        $capabilities = CapabilitySet::fromList([WorkItemLinearAware::class]);
        $context = $this->context([], [], workItemProviders: ['linear']);

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

    public function testGithubTokenMissingWhenGithubProviderActive(): void
    {
        $capabilities = CapabilitySet::fromList([GitProviderGithubAware::class]);
        $context = $this->context([], [], gitProviders: ['github']);

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['GITHUB_TOKEN'], $result->missingGlobalKeys);
    }

    public function testGitlabTokenMissingWhenGitlabProviderActive(): void
    {
        $capabilities = CapabilitySet::fromList([GitProviderGitlabAware::class]);
        $context = $this->context([], [], gitProviders: ['gitlab']);

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['GITLAB_TOKEN'], $result->missingGlobalKeys);
    }

    public function testGithubTokenFromProjectConfig(): void
    {
        $capabilities = CapabilitySet::fromList([GitProviderGithubAware::class]);
        $context = $this->context([], ['githubToken' => 'gh-token'], gitProviders: ['github']);

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testGitlabTokenFromGlobalConfig(): void
    {
        $capabilities = CapabilitySet::fromList([GitProviderGitlabAware::class]);
        $context = $this->context(['GITLAB_TOKEN' => 'gl-token'], [], gitProviders: ['gitlab']);

        $result = $this->guard->check($capabilities, $context);

        $this->assertTrue($result->canProceed);
    }

    public function testDualGitProvidersRequireBothTokensWhenNoEffectiveProvider(): void
    {
        $capabilities = CapabilitySet::fromList([
            GitProviderGithubAware::class,
            GitProviderGitlabAware::class,
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

    public function testEffectiveGithubProviderIgnoresMissingGitlabToken(): void
    {
        $capabilities = CapabilitySet::fromList([
            GitProviderGithubAware::class,
            GitProviderGitlabAware::class,
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
            GitProviderGithubAware::class,
            GitProviderGitlabAware::class,
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
        $capabilities = CapabilitySet::fromList([GitProviderGithubAware::class]);
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
        $capabilities = CapabilitySet::fromList([GitProviderGitlabAware::class]);
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
        $capabilities = CapabilitySet::fromList([GitProviderGithubAware::class]);
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
        $capabilities = CapabilitySet::fromList([WorkItemJiraAware::class, WorkItemLinearAware::class]);
        $context = new CommandContext(
            globalConfig: [
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            projectConfig: ['workItemProvider' => 'auto'],
            hasGitRepository: true,
            workItemProviders: ['jira', 'linear'],
            gitProviders: ['github'],
            isInteractive: true,
            isQuiet: false,
            isAgent: false,
            workItemProviderAmbiguous: true,
        );

        $result = $this->guard->check($capabilities, $context);

        $this->assertFalse($result->canProceed);
        $this->assertSame(['workItemProvider'], $result->missingProjectKeys);
        $this->assertSame([], $result->missingGlobalKeys);
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
        $capabilities = CapabilitySet::fromList([WorkItemJiraAware::class]);
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
     * @param list<string> $workItemProviders
     * @param list<string> $gitProviders
     */
    private function context(
        array $globalConfig,
        array $projectConfig,
        bool $hasGitRepository = true,
        array $workItemProviders = ['jira'],
        array $gitProviders = ['github'],
    ): CommandContext {
        return new CommandContext(
            globalConfig: $globalConfig,
            projectConfig: $projectConfig,
            hasGitRepository: $hasGitRepository,
            workItemProviders: $workItemProviders,
            gitProviders: $gitProviders,
            isInteractive: true,
            isQuiet: false,
            isAgent: false,
        );
    }
}
