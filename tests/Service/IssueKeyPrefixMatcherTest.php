<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Config\ProjectStudConfigKeys;
use App\Enum\IssueTrackerProvider;
use App\Exception\IssueTrackerResolutionException;
use App\Service\IssueKeyPrefixMatcher;
use PHPUnit\Framework\TestCase;

class IssueKeyPrefixMatcherTest extends TestCase
{
    private IssueKeyPrefixMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new IssueKeyPrefixMatcher();
    }

    public function testPrefixOrNullReturnsPrefixForValidKey(): void
    {
        $this->assertSame('SCI', $this->matcher->prefixOrNull(' SCI-12 '));
    }

    public function testPrefixOrNullReturnsNullForEmptyOrInvalidKey(): void
    {
        $this->assertNull($this->matcher->prefixOrNull('   '));
        $this->assertNull($this->matcher->prefixOrNull('not-a-key'));
    }

    public function testProviderClaimingIssueKeyMatchesJiraAndLinear(): void
    {
        $project = $this->project('SCI', 'ENG');

        $this->assertSame(IssueTrackerProvider::Jira, $this->matcher->providerClaimingIssueKey('sci-1', $project));
        $this->assertSame(IssueTrackerProvider::Linear, $this->matcher->providerClaimingIssueKey('ENG-1', $project));
    }

    public function testProviderClaimingIssueKeyReturnsNullWhenInvalidUnclaimedOrAmbiguous(): void
    {
        $this->assertNull($this->matcher->providerClaimingIssueKey('', $this->project('SCI', 'ENG')));
        $this->assertNull($this->matcher->providerClaimingIssueKey('OPS-1', $this->project('SCI', 'ENG')));
        $this->assertNull($this->matcher->providerClaimingIssueKey('SCI-1', $this->project('SCI', 'SCI')));
    }

    public function testProviderForIssueKeyMatchesJiraDefaultProject(): void
    {
        $project = [ProjectStudConfigKeys::JIRA_DEFAULT_PROJECT => 'SCI'];

        $this->assertSame(IssueTrackerProvider::Jira, $this->matcher->providerForIssueKey('SCI-1', $project));
    }

    public function testProviderForIssueKeyRequiresIssueKey(): void
    {
        $this->expectException(IssueTrackerResolutionException::class);
        $this->expectExceptionMessage('issue_tracker_provider.auto_requires_issue_key');

        $this->matcher->providerForIssueKey(null, $this->project('SCI', 'ENG'));
    }

    public function testProviderForIssueKeyThrowsUnknownPrefixForInvalidKey(): void
    {
        $exception = $this->captureResolutionFailure('not-a-key', $this->project('SCI', 'ENG'));

        $this->assertSame('issue_tracker_provider.unknown_prefix', $exception->messageRef->key);
        $this->assertSame('not-a-key', $exception->messageRef->parameters['%prefix%']);
        $this->assertSame('SCI (jira), ENG (linear)', $exception->messageRef->parameters['%configuredKeys%']);
    }

    public function testProviderForIssueKeyThrowsUnknownPrefixListingNoneWhenNothingConfigured(): void
    {
        $exception = $this->captureResolutionFailure('OPS-1', []);

        $this->assertSame('issue_tracker_provider.unknown_prefix', $exception->messageRef->key);
        $this->assertSame('OPS', $exception->messageRef->parameters['%prefix%']);
        $this->assertSame('(none)', $exception->messageRef->parameters['%configuredKeys%']);
    }

    public function testProviderForIssueKeyThrowsWhenPrefixAmbiguous(): void
    {
        $exception = $this->captureResolutionFailure('SCI-1', $this->project('SCI', 'SCI'));

        $this->assertSame('issue_tracker_provider.ambiguous_prefix', $exception->messageRef->key);
        $this->assertSame('SCI', $exception->messageRef->parameters['%prefix%']);
    }

    /**
     * @param array<string, mixed> $project
     */
    private function captureResolutionFailure(string $issueKey, array $project): IssueTrackerResolutionException
    {
        try {
            $this->matcher->providerForIssueKey($issueKey, $project);
        } catch (IssueTrackerResolutionException $e) {
            return $e;
        }

        self::fail('Expected IssueTrackerResolutionException');
    }

    /**
     * @return array<string, mixed>
     */
    private function project(string $jiraKey, string $linearTeamKey): array
    {
        return [
            ProjectStudConfigKeys::PROJECT_KEY => $jiraKey,
            ProjectStudConfigKeys::LINEAR_TEAM_KEY => $linearTeamKey,
        ];
    }
}
