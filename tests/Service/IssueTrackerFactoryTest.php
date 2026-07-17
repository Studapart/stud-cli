<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Config\ProjectStudConfigKeys;
use App\Enum\IssueTrackerProvider;
use App\Exception\IssueTrackerException;
use App\Exception\IssueTrackerResolutionException;
use App\Service\IssueTrackerFactory;
use App\Service\JiraApiClient;
use App\Service\JiraAttachmentService;
use App\Service\JiraIssueTrackerAdapter;
use App\Service\LinearIssueTrackerAdapter;
use PHPUnit\Framework\TestCase;

class IssueTrackerFactoryTest extends TestCase
{
    private IssueTrackerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new IssueTrackerFactory();
    }

    public function testResolveTypeUsesCliOverride(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = ['issueTrackerProvider' => IssueTrackerProvider::Linear->value];

        $this->assertSame(IssueTrackerProvider::Jira->value, $this->factory->resolveType('jira', $global, $project));
        $this->assertSame(IssueTrackerProvider::Linear->value, $this->factory->resolveType('linear', $global, $project));
    }

    public function testResolveTypeRejectsAutoAsExplicitOverride(): void
    {
        $this->expectException(IssueTrackerResolutionException::class);
        $this->expectExceptionMessage('issue_tracker_provider.invalid_override');

        $this->factory->resolveType('auto', $this->dualCredentialsGlobal(), []);
    }

    public function testResolveTypeUsesProjectProviderWhenNoOverride(): void
    {
        $global = $this->dualCredentialsGlobal();

        $this->assertSame(
            IssueTrackerProvider::Linear->value,
            $this->factory->resolveType(null, $global, ['issueTrackerProvider' => IssueTrackerProvider::Linear->value]),
        );
        $this->assertSame(
            IssueTrackerProvider::Jira->value,
            $this->factory->resolveType(null, $global, ['issueTrackerProvider' => IssueTrackerProvider::Jira->value]),
        );
    }

    public function testResolveTypeAutoMatchesJiraPrefixFromIssueKey(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ];

        $this->assertSame(
            IssueTrackerProvider::Jira->value,
            $this->factory->resolveType(null, $global, $project, 'SCI-123'),
        );
    }

    public function testResolveTypeAutoMatchesLinearPrefixFromIssueKey(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ];

        $this->assertSame(
            IssueTrackerProvider::Linear->value,
            $this->factory->resolveType(null, $global, $project, 'ENG-42'),
        );
    }

    public function testResolveTypeAutoMatchesJiraDefaultProjectPrefix(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            ProjectStudConfigKeys::JIRA_DEFAULT_PROJECT => 'SCI',
            'linearTeamKey' => 'ENG',
        ];

        $this->assertSame(
            IssueTrackerProvider::Jira->value,
            $this->factory->resolveType(null, $global, $project, 'SCI-1'),
        );
    }

    public function testResolveTypeAutoThrowsWhenPrefixMatchesBothProviders(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCI',
        ];

        try {
            $this->factory->resolveType(null, $global, $project, 'SCI-99');
        } catch (IssueTrackerResolutionException $e) {
            $this->assertSame('issue_tracker_provider.ambiguous_prefix', $e->messageRef->key);
            $this->assertSame('SCI', $e->messageRef->parameters['%prefix%']);

            return;
        }

        self::fail('Expected IssueTrackerResolutionException');
    }

    public function testResolutionErrorsExposeActionableMessageRefs(): void
    {
        $ambiguous = IssueTrackerResolutionException::ambiguousPrefix('SCI');
        $this->assertSame('issue_tracker_provider.ambiguous_prefix', $ambiguous->messageRef->key);
        $this->assertSame('SCI', $ambiguous->messageRef->parameters['%prefix%']);

        $unknown = IssueTrackerResolutionException::unknownPrefix('OPS', 'SCI (jira)');
        $this->assertSame('issue_tracker_provider.unknown_prefix', $unknown->messageRef->key);
        $this->assertSame('OPS', $unknown->messageRef->parameters['%prefix%']);

        $invalid = IssueTrackerResolutionException::invalidOverride('auto');
        $this->assertSame('issue_tracker_provider.invalid_override', $invalid->messageRef->key);
        $this->assertSame('auto', $invalid->messageRef->parameters['%value%']);

        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('issue_tracker_provider.missing_jira_configuration');
        $this->factory->resolveType('jira', $this->linearOnlyGlobal(), []);
    }

    public function testResolveTypeAutoThrowsWhenPrefixMatchesNeitherProvider(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ];

        $this->expectException(IssueTrackerResolutionException::class);
        $this->expectExceptionMessage('issue_tracker_provider.unknown_prefix');

        $this->factory->resolveType(null, $global, $project, 'OPS-1');
    }

    public function testResolveTypeAutoRequiresIssueKeyWhenDualConfigured(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = ['issueTrackerProvider' => IssueTrackerProvider::Auto->value];

        $this->expectException(IssueTrackerResolutionException::class);
        $this->expectExceptionMessage('issue_tracker_provider.auto_requires_issue_key');

        $this->factory->resolveType(null, $global, $project, null);
    }

    public function testResolveTypeUsesGlobalSingleProvider(): void
    {
        $this->assertSame(IssueTrackerProvider::Jira->value, $this->factory->resolveType(null, $this->jiraOnlyGlobal(), []));
        $this->assertSame(IssueTrackerProvider::Linear->value, $this->factory->resolveType(null, $this->linearOnlyGlobal(), []));
    }

    public function testResolveTypeThrowsWhenNoProviderConfigured(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('issue_tracker_provider.not_configured');

        $this->factory->resolveType(null, $this->bothProvidersGlobal(), []);
    }

    public function testGetEffectiveProviderIdReturnsResolvedProvider(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ];

        $this->assertSame(
            IssueTrackerProvider::Linear,
            $this->factory->getEffectiveProvider(null, $global, $project, 'ENG-1'),
        );
        $this->assertSame(
            IssueTrackerProvider::Jira,
            $this->factory->getEffectiveProvider(null, $global, $project, 'SCI-1'),
        );
    }

    public function testResolveTypeTreatsWhitespaceOverrideAsUnset(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = [
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ];

        $this->assertSame(
            IssueTrackerProvider::Linear->value,
            $this->factory->resolveType('   ', $global, $project, 'ENG-9'),
        );
    }

    public function testResolveTypeThrowsWhenProjectProviderInvalidAndDualConfigured(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('issue_tracker_provider.not_configured');

        $this->factory->resolveType(null, $this->dualCredentialsGlobal(), ['issueTrackerProvider' => 'invalid']);
    }

    public function testResolveTypeFallsBackToLinearCredentialsWhenBothListedButOnlyLinearConfigured(): void
    {
        $global = [
            'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
            'LINEAR_API_KEY' => 'lin',
        ];

        $this->assertSame(
            IssueTrackerProvider::Linear->value,
            $this->factory->resolveType(null, $global, ['issueTrackerProvider' => 'invalid']),
        );
    }

    public function testResolveTypeFallsBackToJiraCredentialsWhenBothListedButOnlyJiraConfigured(): void
    {
        $global = [
            'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
        ];

        $this->assertSame(
            IssueTrackerProvider::Jira->value,
            $this->factory->resolveType(null, $global, ['issueTrackerProvider' => 'invalid']),
        );
    }

    public function testResolveTypeSkipsAutoPrefixWhenGlobalListsOnlyOneProviderDespiteDualCredentials(): void
    {
        $global = $this->dualCredentialsGlobal();
        $global['ISSUE_TRACKER_PROVIDERS'] = [IssueTrackerProvider::Jira->value];
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ];

        $this->assertSame(
            IssueTrackerProvider::Jira->value,
            $this->factory->resolveType(null, $global, $project, 'ENG-1'),
        );
    }

    public function testAssertCredentialsThrowsWhenLinearSelectedWithoutApiKey(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('issue_tracker_provider.missing_linear_api_key');

        $this->factory->assertCredentials(IssueTrackerProvider::Linear->value, $this->linearOnlyGlobal());
    }

    public function testAssertCredentialsThrowsWhenJiraSelectedWithoutCredentials(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('issue_tracker_provider.missing_jira_configuration');

        $this->factory->assertCredentials(IssueTrackerProvider::Jira->value, $this->jiraOnlyGlobal());
    }

    public function testAssertCredentialsPassesWhenCredentialsPresent(): void
    {
        $this->factory->assertCredentials(IssueTrackerProvider::Jira->value, $this->jiraCredentialsGlobal());
        $this->factory->assertCredentials(IssueTrackerProvider::Linear->value, ['LINEAR_API_KEY' => 'lin']);

        $this->addToAssertionCount(2);
    }

    public function testCreateReturnsJiraAdapter(): void
    {
        $jira = $this->createMock(JiraApiClient::class);
        $attachments = $this->createMock(JiraAttachmentService::class);

        $provider = $this->factory->create(IssueTrackerProvider::Jira->value, $jira, $attachments);

        $this->assertInstanceOf(JiraIssueTrackerAdapter::class, $provider);
    }

    public function testCreateReturnsLinearAdapter(): void
    {
        $linearApiClient = $this->createMock(\App\Service\LinearApiClient::class);

        $provider = $this->factory->create(IssueTrackerProvider::Linear->value, linearApiClient: $linearApiClient);

        $this->assertInstanceOf(LinearIssueTrackerAdapter::class, $provider);
    }

    public function testCreateRequiresLinearClientForLinearType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->create(IssueTrackerProvider::Linear->value);
    }

    public function testCreateRequiresJiraDependenciesForJiraType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->create(IssueTrackerProvider::Jira->value);
    }

    public function testCreateForProviderThrowsWhenJiraClientsMissing(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('issue_tracker_provider.missing_jira_configuration');

        $this->factory->createForProvider(IssueTrackerProvider::Jira->value, null, null, null);
    }

    public function testCreateForProviderThrowsWhenLinearClientMissing(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('issue_tracker_provider.missing_linear_api_key');

        $this->factory->createForProvider(IssueTrackerProvider::Linear->value, null, null, null);
    }

    public function testResolveTypeRejectsUnknownOverride(): void
    {
        $this->expectException(IssueTrackerResolutionException::class);
        $this->expectExceptionMessage('issue_tracker_provider.invalid_override');

        $this->factory->resolveType('not-a-provider', $this->jiraOnlyGlobal(), []);
    }

    public function testResolveTypeAutoMatchesWhenProjectProviderUnset(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = [
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ];

        $this->assertSame(
            IssueTrackerProvider::Jira->value,
            $this->factory->resolveType(null, $global, $project, 'SCI-7'),
        );
    }

    public function testResolveTypeReadsLegacyWorkItemProviderKey(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = [ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER => IssueTrackerProvider::Jira->value];

        $this->assertSame(
            IssueTrackerProvider::Jira->value,
            $this->factory->resolveType(null, $global, $project),
        );
    }

    public function testResolveTypeAutoThrowsWhenIssueKeyFormatInvalid(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = ['issueTrackerProvider' => IssueTrackerProvider::Auto->value];

        $this->expectException(IssueTrackerResolutionException::class);
        $this->expectExceptionMessage('issue_tracker_provider.unknown_prefix');

        $this->factory->resolveType(null, $global, $project, 'not-a-key');
    }

    public function testResolveTypeAutoUnknownPrefixListsNoneWhenNoProjectKeysConfigured(): void
    {
        $global = $this->dualCredentialsGlobal();
        $project = ['issueTrackerProvider' => IssueTrackerProvider::Auto->value];

        try {
            $this->factory->resolveType(null, $global, $project, 'SCI-1');
        } catch (IssueTrackerResolutionException $e) {
            $this->assertSame('issue_tracker_provider.unknown_prefix', $e->messageRef->key);
            $this->assertSame('SCI', $e->messageRef->parameters['%prefix%']);
            $this->assertSame('(none)', $e->messageRef->parameters['%configuredKeys%']);

            return;
        }

        self::fail('Expected IssueTrackerResolutionException');
    }

    public function testOverrideFailsWhenCredentialsMissingForSelectedProvider(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('issue_tracker_provider.missing_linear_api_key');

        $this->factory->resolveType('linear', $this->jiraOnlyGlobal(), []);
    }

    public function testPinnedProviderIgnoresOtherPrefixAtResolveTime(): void
    {
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Jira->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCIL',
        ];

        $this->assertSame(
            IssueTrackerProvider::Jira->value,
            $this->factory->resolveType(null, $this->dualCredentialsGlobal(), $project, 'SCIL-195'),
        );
        $this->assertSame(
            IssueTrackerProvider::Linear,
            $this->factory->providerClaimingIssueKey('SCIL-195', $project),
        );
    }

    public function testAutoResolvesOtherPrefix(): void
    {
        $project = [
            'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCIL',
        ];

        $this->assertSame(
            IssueTrackerProvider::Linear->value,
            $this->factory->resolveType(null, $this->dualCredentialsGlobal(), $project, 'SCIL-195'),
        );
    }

    public function testProviderClaimingIssueKeyReturnsNullForEmptyOrInvalidKey(): void
    {
        $project = ['projectKey' => 'SCI', 'linearTeamKey' => 'SCIL'];

        $this->assertNull($this->factory->providerClaimingIssueKey('', $project));
        $this->assertNull($this->factory->providerClaimingIssueKey('   ', $project));
        $this->assertNull($this->factory->issueKeyPrefixOrNull(''));
        $this->assertNull($this->factory->issueKeyPrefixOrNull('not-a-key'));
    }

    public function testProviderClaimingIssueKeyReturnsNullWhenPrefixAmbiguous(): void
    {
        $project = [
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCI',
        ];

        $this->assertNull($this->factory->providerClaimingIssueKey('SCI-1', $project));
    }

    /**
     * @return array<string, mixed>
     */
    private function jiraOnlyGlobal(): array
    {
        return ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value]];
    }

    /**
     * @return array<string, mixed>
     */
    private function linearOnlyGlobal(): array
    {
        return ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Linear->value]];
    }

    /**
     * @return array<string, mixed>
     */
    private function bothProvidersGlobal(): array
    {
        return ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value]];
    }

    /**
     * @return array<string, mixed>
     */
    private function dualCredentialsGlobal(): array
    {
        return [
            'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
            'LINEAR_API_KEY' => 'lin',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jiraCredentialsGlobal(): array
    {
        return [
            'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value],
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
        ];
    }
}
