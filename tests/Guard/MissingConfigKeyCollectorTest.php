<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Enum\IssueTrackerProvider;
use App\Guard\Capability\ConfluenceAware;
use App\Guard\Capability\GitHosting\GithubAware;
use App\Guard\Capability\GitHosting\GitlabAware;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Guard\CapabilitySet;
use App\Guard\CommandContext;
use App\Guard\MissingConfigKeyCollector;
use PHPUnit\Framework\TestCase;

class MissingConfigKeyCollectorTest extends TestCase
{
    private MissingConfigKeyCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new MissingConfigKeyCollector();
    }

    public function testMissingGlobalKeysDeduplicatesJiraAndConfluenceCredentials(): void
    {
        $capabilities = CapabilitySet::fromList([JiraAware::class, ConfluenceAware::class]);
        $context = $this->context(['JIRA_URL' => 'https://example.atlassian.net'], [], issueTrackerProviders: ['jira']);

        $this->assertSame(['JIRA_EMAIL', 'JIRA_API_TOKEN'], $this->collector->missingGlobalKeys($capabilities, $context));
    }

    public function testMissingGlobalKeysListsCredentialsAndTokensInOrder(): void
    {
        $capabilities = CapabilitySet::fromList([JiraAware::class, LinearAware::class, GithubAware::class, GitlabAware::class]);
        $context = $this->context(
            ['JIRA_URL' => 'u', 'JIRA_EMAIL' => 'e', 'JIRA_API_TOKEN' => ' '],
            [],
            issueTrackerProviders: [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
            gitProviders: ['github', 'gitlab'],
        );

        $this->assertSame(
            ['JIRA_API_TOKEN', 'LINEAR_API_KEY', 'GITHUB_TOKEN', 'GITLAB_TOKEN'],
            $this->collector->missingGlobalKeys($capabilities, $context),
        );
    }

    public function testMissingGlobalKeysIgnoresGitTokenWhenProviderNotCollected(): void
    {
        $capabilities = CapabilitySet::fromList([GithubAware::class, GitlabAware::class]);
        $context = $this->context([], [], gitProviders: ['github']);

        $this->assertSame(['GITHUB_TOKEN'], $this->collector->missingGlobalKeys($capabilities, $context));
    }

    public function testGitTokensSatisfiedFromGlobalProjectAndLegacyConfig(): void
    {
        $capabilities = CapabilitySet::fromList([GithubAware::class, GitlabAware::class]);

        $this->assertSame([], $this->collector->missingGlobalKeys(
            $capabilities,
            $this->context(['GITLAB_TOKEN' => 'gl'], ['githubToken' => 'gh'], gitProviders: ['github', 'gitlab']),
        ));
        $this->assertSame(['GITLAB_TOKEN'], $this->collector->missingGlobalKeys(
            $capabilities,
            $this->context(['GIT_TOKEN' => 'legacy', 'GIT_PROVIDER' => ' GitHub '], [], gitProviders: ['github', 'gitlab']),
        ));
    }

    public function testLegacyGitTokenRejectedWhenEmptyOrProviderInvalid(): void
    {
        $capabilities = CapabilitySet::fromList([GithubAware::class]);

        $this->assertSame(['GITHUB_TOKEN'], $this->collector->missingGlobalKeys(
            $capabilities,
            $this->context(['GIT_TOKEN' => '  ', 'GIT_PROVIDER' => 'github', 'GITHUB_TOKEN' => ['not-a-string']], []),
        ));
        $this->assertSame(['GITHUB_TOKEN'], $this->collector->missingGlobalKeys(
            $capabilities,
            $this->context(['GIT_TOKEN' => 'legacy', 'GIT_PROVIDER' => 42], []),
        ));
    }

    public function testMissingProjectKeysRequiresProviderWhenAmbiguousAndUnresolved(): void
    {
        $capabilities = CapabilitySet::fromList([LinearAware::class, ProjectBaseBranchAware::class]);
        $context = $this->context([], [], issueTrackerProviderAmbiguous: true);

        $this->assertSame(['issueTrackerProvider', 'baseBranch'], $this->collector->missingProjectKeys($capabilities, $context));
    }

    public function testMissingProjectKeysSkipsProviderWhenResolvedOrNotTrackerAware(): void
    {
        $resolved = $this->context([], ['baseBranch' => 'main'], issueTrackerProviders: ['linear'], issueTrackerProviderAmbiguous: true);
        $untracked = $this->context([], null, issueTrackerProviderAmbiguous: true);

        $this->assertSame([], $this->collector->missingProjectKeys(
            CapabilitySet::fromList([LinearAware::class, ProjectBaseBranchAware::class]),
            $resolved,
        ));
        $this->assertSame(['baseBranch'], $this->collector->missingProjectKeys(
            CapabilitySet::fromList([ProjectBaseBranchAware::class]),
            $untracked,
        ));
    }

    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @param list<string>              $issueTrackerProviders
     * @param list<string>              $gitProviders
     */
    private function context(
        array $globalConfig,
        ?array $projectConfig,
        array $issueTrackerProviders = [],
        array $gitProviders = ['github'],
        bool $issueTrackerProviderAmbiguous = false,
    ): CommandContext {
        return new CommandContext(
            globalConfig: $globalConfig,
            projectConfig: $projectConfig,
            hasGitRepository: true,
            issueTrackerProviders: $issueTrackerProviders,
            gitProviders: $gitProviders,
            isInteractive: true,
            isQuiet: false,
            isAgent: false,
            issueTrackerProviderAmbiguous: $issueTrackerProviderAmbiguous,
        );
    }
}
