<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Enum\IssueTrackerProvider;
use App\Enum\WorkItemCommandProfile;
use App\Exception\IssueTrackerResolutionException;
use App\Guard\DTO\ProviderResolutionRequest;
use App\Guard\Resolver\IssueTrackerProviderResolver;
use App\Service\IssueTrackerFactory;
use PHPUnit\Framework\TestCase;

final class IssueTrackerProviderResolverTest extends TestCase
{
    private IssueTrackerProviderResolver $resolver;

    /** @var array<string, mixed> */
    private array $dualGlobalConfig;

    /** @var array<string, mixed> */
    private array $dualProjectConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new IssueTrackerProviderResolver();
        $this->dualGlobalConfig = [
            'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
            'LINEAR_API_KEY' => 'lin',
        ];
        $this->dualProjectConfig = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCIL',
        ];
    }

    public function testOverrideReturnsSingleProvider(): void
    {
        $result = $this->resolver->resolve($this->request(
            WorkItemCommandProfile::KeySingle,
            cliOverride: IssueTrackerProvider::Linear->value,
            issueKey: 'SCI-1',
        ));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['linear'], $result->providers);
        $this->assertSame('override', $result->inferredFrom);
    }

    public function testInvalidCliOverrideBlocks(): void
    {
        $result = $this->resolver->resolve($this->request(
            WorkItemCommandProfile::KeySingle,
            cliOverride: IssueTrackerProvider::Auto->value,
            issueKey: 'SCI-1',
        ));

        $this->assertTrue($result->isBlocked());
        $this->assertSame('issue_tracker_provider.invalid_override', $result->block?->key);
    }

    public function testIssueKeyInfersLinearProvider(): void
    {
        $result = $this->resolver->resolve($this->request(
            WorkItemCommandProfile::KeySingle,
            issueKey: 'SCIL-42',
        ));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['linear'], $result->providers);
        $this->assertSame('issue_key', $result->inferredFrom);
    }

    public function testDualAggregateUnderAuto(): void
    {
        $result = $this->resolver->resolve($this->request(WorkItemCommandProfile::DualAggregate));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira', 'linear'], $result->providers);
        $this->assertSame('dual_default', $result->inferredFrom);
    }

    public function testSearchBlocksUnderDualPmWithoutOverride(): void
    {
        $result = $this->resolver->resolve($this->request(WorkItemCommandProfile::SearchSingle));

        $this->assertTrue($result->isBlocked());
        $this->assertSame('issue_tracker_provider.search_requires_explicit_provider', $result->block?->key);
    }

    public function testProjectsLabelsBlocksJiraScope(): void
    {
        $result = $this->resolver->resolve($this->request(
            WorkItemCommandProfile::LinearOnly,
            scopeKey: 'SCI',
        ));

        $this->assertTrue($result->isBlocked());
        $this->assertSame('project.labels.requires_linear', $result->block?->key);
    }

    public function testScopeDiscoveryResolvesJiraProvider(): void
    {
        $result = $this->resolver->resolve($this->request(
            WorkItemCommandProfile::ScopeDiscovery,
            scopeKey: 'SCI',
        ));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira'], $result->providers);
        $this->assertSame('scope_key', $result->inferredFrom);
    }

    public function testAttachmentUrlInfersLinearProvider(): void
    {
        $result = $this->resolver->resolve($this->request(
            WorkItemCommandProfile::KeySingle,
            attachmentUrl: 'https://uploads.linear.app/file',
        ));

        $this->assertSame(['linear'], $result->providers);
    }

    public function testUnknownAttachmentHostBlocks(): void
    {
        $result = $this->resolver->resolve($this->request(
            WorkItemCommandProfile::KeySingle,
            attachmentUrl: 'https://example.com/file',
        ));

        $this->assertTrue($result->isBlocked());
        $this->assertSame('item.download.error_unknown_attachment_host', $result->block?->key);
    }

    public function testScopeDiscoveryBlocksWhenUnknown(): void
    {
        $result = $this->resolver->resolve($this->request(
            WorkItemCommandProfile::ScopeDiscovery,
            scopeKey: 'UNKNOWN',
        ));

        $this->assertTrue($result->isBlocked());
    }

    public function testAttachmentUrlInfersJiraProvider(): void
    {
        $result = $this->resolver->resolve($this->request(
            WorkItemCommandProfile::KeySingle,
            attachmentUrl: 'https://example.atlassian.net/secure/attachment/1/file.png',
        ));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira'], $result->providers);
        $this->assertSame('attachment_url', $result->inferredFrom);
    }

    public function testAmbiguousIssueKeyBlocksBeforeCommandRule(): void
    {
        $projectConfig = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCI',
        ];

        $result = $this->resolver->resolve(new ProviderResolutionRequest(
            commandName: 'items:show',
            commandProfile: WorkItemCommandProfile::KeySingle,
            globalConfig: $this->dualGlobalConfig,
            projectConfig: $projectConfig,
            issueKey: 'SCI-99',
        ));

        $this->assertTrue($result->isBlocked());
        $this->assertSame('issue_tracker_provider.ambiguous_prefix', $result->block?->key);
    }

    public function testFilterByNameDualAggregateWhenAuto(): void
    {
        $result = $this->resolver->resolve($this->request(WorkItemCommandProfile::FilterByName));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira', 'linear'], $result->providers);
    }

    public function testResolvesGlobalSingleWhenProjectExplicitJira(): void
    {
        $projectConfig = ['issueTrackerProvider' => IssueTrackerProvider::Jira->value, 'projectKey' => 'SCI'];

        $result = $this->resolver->resolve(new ProviderResolutionRequest(
            commandName: 'filters:list',
            commandProfile: WorkItemCommandProfile::DualAggregate,
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['jira'], 'JIRA_URL' => 'https://x.test', 'JIRA_EMAIL' => 'a@b.c', 'JIRA_API_TOKEN' => 't'],
            projectConfig: $projectConfig,
        ));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira'], $result->providers);
    }

    public function testUnexpectedThrowableMapsToAmbiguousGuardError(): void
    {
        $factory = $this->createMock(IssueTrackerFactory::class);
        $factory->method('resolveType')->willReturnCallback(static function (): never {
            static $calls = 0;
            ++$calls;
            if ($calls === 1) {
                throw IssueTrackerResolutionException::invalidOverride('auto');
            }

            throw new \RuntimeException('boom');
        });
        $resolver = new IssueTrackerProviderResolver(
            issueTrackerFactory: $factory,
            issueTrackerResolver: new \App\Service\IssueTrackerResolver(factory: $factory),
        );

        $result = $resolver->resolve(new ProviderResolutionRequest(
            commandName: 'filters:list',
            commandProfile: WorkItemCommandProfile::DualAggregate,
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['jira'], 'JIRA_URL' => 'https://x.test', 'JIRA_EMAIL' => 'a@b.c', 'JIRA_API_TOKEN' => 't'],
            projectConfig: ['issueTrackerProvider' => IssueTrackerProvider::Jira->value],
        ));

        $this->assertTrue($result->isBlocked());
        $this->assertSame('guard.error.ambiguous_issue_tracker_provider', $result->block?->key);
    }

    public function testIssueKeyResolutionExceptionSurfacesMessageRef(): void
    {
        $factory = $this->createMock(IssueTrackerFactory::class);
        $factory->method('resolveType')->willThrowException(
            IssueTrackerResolutionException::unknownPrefix('OPS', 'SCI (jira)'),
        );
        $resolver = new IssueTrackerProviderResolver(issueTrackerFactory: $factory);

        $result = $resolver->resolve(new ProviderResolutionRequest(
            commandName: 'items:show',
            commandProfile: WorkItemCommandProfile::KeySingle,
            globalConfig: $this->dualGlobalConfig,
            projectConfig: $this->dualProjectConfig,
            issueKey: 'OPS-1',
        ));

        $this->assertTrue($result->isBlocked());
        $this->assertSame('issue_tracker_provider.unknown_prefix', $result->block?->key);
    }

    public function testResolveTypeFallbackAfterActiveProviderFails(): void
    {
        $factory = $this->createMock(IssueTrackerFactory::class);
        $factory->method('resolveType')->willReturnCallback(static function (): string {
            static $calls = 0;
            ++$calls;
            if ($calls === 1) {
                throw IssueTrackerResolutionException::invalidOverride('auto');
            }

            return IssueTrackerProvider::Jira->value;
        });
        $resolver = new IssueTrackerProviderResolver(
            issueTrackerFactory: $factory,
            issueTrackerResolver: new \App\Service\IssueTrackerResolver(factory: $factory),
        );

        $result = $resolver->resolve(new ProviderResolutionRequest(
            commandName: 'filters:list',
            commandProfile: WorkItemCommandProfile::DualAggregate,
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['jira'], 'JIRA_URL' => 'https://x.test', 'JIRA_EMAIL' => 'a@b.c', 'JIRA_API_TOKEN' => 't'],
            projectConfig: ['issueTrackerProvider' => IssueTrackerProvider::Jira->value],
        ));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira'], $result->providers);
        $this->assertSame('global_single', $result->inferredFrom);
    }

    public function testDualAggregateSkippedWhenGlobalListsJiraOnly(): void
    {
        $global = $this->dualGlobalConfig;
        $global['ISSUE_TRACKER_PROVIDERS'] = ['jira'];

        $result = $this->resolver->resolve(new ProviderResolutionRequest(
            commandName: 'filters:list',
            commandProfile: WorkItemCommandProfile::DualAggregate,
            globalConfig: $global,
            projectConfig: $this->dualProjectConfig,
        ));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira'], $result->providers);
    }

    public function testDualAggregateSkippedWhenProjectNotAuto(): void
    {
        $projectConfig = [
            'issueTrackerProvider' => IssueTrackerProvider::Jira->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCIL',
        ];

        $result = $this->resolver->resolve(new ProviderResolutionRequest(
            commandName: 'filters:list',
            commandProfile: WorkItemCommandProfile::DualAggregate,
            globalConfig: $this->dualGlobalConfig,
            projectConfig: $projectConfig,
        ));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira'], $result->providers);
    }

    public function testResolveTypeResolutionExceptionMapsToBlockedResult(): void
    {
        $factory = $this->createMock(IssueTrackerFactory::class);
        $factory->method('resolveType')->willReturnCallback(static function (): never {
            static $calls = 0;
            ++$calls;
            if ($calls === 1) {
                throw IssueTrackerResolutionException::invalidOverride('auto');
            }

            throw IssueTrackerResolutionException::unknownPrefix('OPS', 'SCI (jira)');
        });
        $resolver = new IssueTrackerProviderResolver(
            issueTrackerFactory: $factory,
            issueTrackerResolver: new \App\Service\IssueTrackerResolver(factory: $factory),
        );

        $result = $resolver->resolve(new ProviderResolutionRequest(
            commandName: 'filters:list',
            commandProfile: WorkItemCommandProfile::DualAggregate,
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['jira'], 'JIRA_URL' => 'https://x.test', 'JIRA_EMAIL' => 'a@b.c', 'JIRA_API_TOKEN' => 't'],
            projectConfig: ['issueTrackerProvider' => IssueTrackerProvider::Jira->value],
        ));

        $this->assertTrue($result->isBlocked());
        $this->assertSame('issue_tracker_provider.unknown_prefix', $result->block?->key);
    }

    public function testDualAggregateUsesAutoWhenProjectProviderMissing(): void
    {
        $projectConfig = ['projectKey' => 'SCI', 'linearTeamKey' => 'SCIL'];

        $result = $this->resolver->resolve(new ProviderResolutionRequest(
            commandName: 'filters:list',
            commandProfile: WorkItemCommandProfile::DualAggregate,
            globalConfig: $this->dualGlobalConfig,
            projectConfig: $projectConfig,
        ));

        $this->assertFalse($result->isBlocked());
        $this->assertSame(['jira', 'linear'], $result->providers);
    }

    public function testDualAggregateSkippedWhenLinearNotInGlobalProviders(): void
    {
        $result = $this->resolver->resolve(new ProviderResolutionRequest(
            commandName: 'filters:list',
            commandProfile: WorkItemCommandProfile::DualAggregate,
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['jira']],
            projectConfig: $this->dualProjectConfig,
        ));

        $this->assertNotSame('dual_default', $result->inferredFrom);
    }

    private function request(
        WorkItemCommandProfile $profile,
        ?string $cliOverride = null,
        ?string $issueKey = null,
        ?string $scopeKey = null,
        ?string $attachmentUrl = null,
    ): ProviderResolutionRequest {
        return new ProviderResolutionRequest(
            commandName: 'items:show',
            commandProfile: $profile,
            globalConfig: $this->dualGlobalConfig,
            projectConfig: $this->dualProjectConfig,
            cliOverride: $cliOverride,
            issueKey: $issueKey,
            scopeKey: $scopeKey,
            attachmentUrl: $attachmentUrl,
        );
    }
}
