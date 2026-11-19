<?php

declare(strict_types=1);

namespace App\Tests\Guard\Resolver;

use App\Enum\WorkItemCommandProfile;
use App\Exception\IssueTrackerResolutionException;
use App\Guard\DTO\ProviderResolutionRequest;
use App\Guard\DTO\ProviderResolutionResult;
use App\Guard\Resolver\IssueTrackerResolutionDeps;
use App\Guard\Resolver\ProviderHintResolver;
use App\Service\IssueTrackerFactory;
use PHPUnit\Framework\TestCase;

class ProviderHintResolverTest extends TestCase
{
    private const GLOBAL_CONFIG = [
        'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
        'JIRA_URL' => 'https://example.atlassian.net',
        'JIRA_EMAIL' => 'user@example.com',
        'JIRA_API_TOKEN' => 'token',
        'LINEAR_API_KEY' => 'lin',
    ];

    private const PROJECT_CONFIG = [
        'issueTrackerProvider' => 'auto',
        'projectKey' => 'SCI',
        'linearTeamKey' => 'SCIL',
    ];

    private ProviderHintResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProviderHintResolver();
    }

    public function testIssueKeyIsNormalizedAndResolved(): void
    {
        $result = $this->resolve(WorkItemCommandProfile::KeySingle, issueKey: ' scil-42 ');

        $this->assertSame(['linear'], $result?->providers);
        $this->assertSame('issue_key', $result?->inferredFrom);
    }

    public function testBranchIssueKeyUsedWhenIssueKeyBlank(): void
    {
        $result = $this->resolve(WorkItemCommandProfile::KeyOrBranch, issueKey: '  ', branchIssueKey: 'SCI-7');

        $this->assertSame(['jira'], $result?->providers);
        $this->assertSame('issue_key', $result?->inferredFrom);
    }

    public function testIssueKeyResolutionExceptionBlocks(): void
    {
        $factory = $this->createStub(IssueTrackerFactory::class);
        $factory->method('resolveType')->willThrowException(
            IssueTrackerResolutionException::unknownPrefix('OPS', 'SCI (jira)'),
        );
        $resolver = new ProviderHintResolver(new IssueTrackerResolutionDeps(factory: $factory));

        $result = $resolver->resolve($this->request(WorkItemCommandProfile::KeySingle, issueKey: 'OPS-1'), self::PROJECT_CONFIG);

        $this->assertTrue($result?->isBlocked());
        $this->assertSame('issue_tracker_provider.unknown_prefix', $result?->block?->key);
    }

    public function testAttachmentUrlInfersProviderForKeySingle(): void
    {
        $result = $this->resolve(WorkItemCommandProfile::KeySingle, attachmentUrl: 'https://example.atlassian.net/secure/attachment/1/a.png');

        $this->assertSame(['jira'], $result?->providers);
        $this->assertSame('attachment_url', $result?->inferredFrom);
    }

    public function testUnknownAttachmentHostBlocks(): void
    {
        $result = $this->resolve(WorkItemCommandProfile::KeySingle, attachmentUrl: 'https://example.com/file');

        $this->assertSame('item.download.error_unknown_attachment_host', $result?->block?->key);
    }

    public function testScopeKeyResolvesForScopeProfiles(): void
    {
        $result = $this->resolve(WorkItemCommandProfile::LinearOnly, scopeKey: 'scil');

        $this->assertSame(['linear'], $result?->providers);
        $this->assertSame('scope_key', $result?->inferredFrom);
    }

    public function testUnknownScopeKeyBlocks(): void
    {
        $result = $this->resolve(WorkItemCommandProfile::ScopeDiscovery, scopeKey: 'UNKNOWN');

        $this->assertTrue($result?->isBlocked());
    }

    public function testReturnsNullWhenNoHintAppliesToProfile(): void
    {
        $this->assertNull($this->resolve(WorkItemCommandProfile::ScopeDiscovery, issueKey: 'SCI-1'));
        $this->assertNull($this->resolve(WorkItemCommandProfile::KeyOrBranch, attachmentUrl: 'https://linear.app/f'));
        $this->assertNull($this->resolve(WorkItemCommandProfile::KeySingle, scopeKey: 'SCI'));
        $this->assertNull($this->resolve(WorkItemCommandProfile::ScopeDiscovery, scopeKey: ' '));
    }

    private function resolve(
        WorkItemCommandProfile $profile,
        ?string $issueKey = null,
        ?string $branchIssueKey = null,
        ?string $scopeKey = null,
        ?string $attachmentUrl = null,
    ): ?ProviderResolutionResult {
        return $this->resolver->resolve(
            $this->request($profile, $issueKey, $branchIssueKey, $scopeKey, $attachmentUrl),
            self::PROJECT_CONFIG,
        );
    }

    private function request(
        WorkItemCommandProfile $profile,
        ?string $issueKey = null,
        ?string $branchIssueKey = null,
        ?string $scopeKey = null,
        ?string $attachmentUrl = null,
    ): ProviderResolutionRequest {
        return new ProviderResolutionRequest(
            commandName: 'items:show',
            commandProfile: $profile,
            globalConfig: self::GLOBAL_CONFIG,
            projectConfig: self::PROJECT_CONFIG,
            issueKey: $issueKey,
            scopeKey: $scopeKey,
            branchIssueKey: $branchIssueKey,
            attachmentUrl: $attachmentUrl,
        );
    }
}
