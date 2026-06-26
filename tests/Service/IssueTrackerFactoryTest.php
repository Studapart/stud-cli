<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\IssueTrackerException;
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
        $global = $this->bothProvidersGlobal();
        $project = ['workItemProvider' => 'linear'];

        $this->assertSame('jira', $this->factory->resolveType('jira', $global, $project));
        $this->assertSame('linear', $this->factory->resolveType('linear', $global, $project));
    }

    public function testResolveTypeTreatsAutoOverrideAsUnset(): void
    {
        $global = $this->bothProvidersGlobal();
        $project = ['workItemProvider' => 'linear'];

        $this->assertSame('linear', $this->factory->resolveType('auto', $global, $project));
    }

    public function testResolveTypeUsesProjectProviderWhenNoOverride(): void
    {
        $global = $this->bothProvidersGlobal();

        $this->assertSame(
            'linear',
            $this->factory->resolveType(null, $global, ['workItemProvider' => 'linear']),
        );
        $this->assertSame(
            'jira',
            $this->factory->resolveType(null, $global, ['workItemProvider' => 'jira']),
        );
    }

    public function testResolveTypeAutoPrefersJiraWhenBothProvidersAndJiraCredentials(): void
    {
        $global = $this->bothProvidersGlobal();
        $global['JIRA_URL'] = 'https://jira.example.com';
        $global['JIRA_EMAIL'] = 'user@example.com';
        $global['JIRA_API_TOKEN'] = 'token';
        $global['LINEAR_API_KEY'] = 'lin';

        $this->assertSame(
            'jira',
            $this->factory->resolveType(null, $global, ['workItemProvider' => 'auto']),
        );
    }

    public function testResolveTypeAutoFallsBackToLinearWhenOnlyLinearCredentials(): void
    {
        $global = $this->bothProvidersGlobal();
        $global['LINEAR_API_KEY'] = 'lin';

        $this->assertSame(
            'linear',
            $this->factory->resolveType(null, $global, ['workItemProvider' => 'auto']),
        );
    }

    public function testResolveTypeUsesGlobalSingleProvider(): void
    {
        $this->assertSame('jira', $this->factory->resolveType(null, $this->jiraOnlyGlobal(), []));
        $this->assertSame('linear', $this->factory->resolveType(null, $this->linearOnlyGlobal(), []));
    }

    public function testResolveTypeThrowsWhenNoProviderConfigured(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('work_item_provider.not_configured');

        $this->factory->resolveType(null, $this->bothProvidersGlobal(), []);
    }

    public function testAssertCredentialsThrowsWhenLinearSelectedWithoutApiKey(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('work_item_provider.missing_linear_api_key');

        $this->factory->assertCredentials('linear', $this->linearOnlyGlobal());
    }

    public function testAssertCredentialsThrowsWhenJiraSelectedWithoutCredentials(): void
    {
        $this->expectException(IssueTrackerException::class);
        $this->expectExceptionMessage('work_item_provider.missing_jira_configuration');

        $this->factory->assertCredentials('jira', $this->jiraOnlyGlobal());
    }

    public function testAssertCredentialsPassesWhenCredentialsPresent(): void
    {
        $this->factory->assertCredentials('jira', $this->jiraCredentialsGlobal());
        $this->factory->assertCredentials('linear', ['LINEAR_API_KEY' => 'lin']);

        $this->addToAssertionCount(2);
    }

    public function testCreateReturnsJiraAdapter(): void
    {
        $jira = $this->createMock(JiraApiClient::class);
        $attachments = $this->createMock(JiraAttachmentService::class);

        $provider = $this->factory->create('jira', $jira, $attachments);

        $this->assertInstanceOf(JiraIssueTrackerAdapter::class, $provider);
    }

    public function testCreateReturnsLinearAdapter(): void
    {
        $provider = $this->factory->create('linear');

        $this->assertInstanceOf(LinearIssueTrackerAdapter::class, $provider);
    }

    public function testCreateRequiresJiraDependenciesForJiraType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->create('jira');
    }

    public function testResolveTypeRejectsUnknownOverride(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->resolveType('not-a-provider', $this->jiraOnlyGlobal(), []);
    }

    /**
     * @return array<string, mixed>
     */
    private function jiraOnlyGlobal(): array
    {
        return ['WORK_ITEM_PROVIDERS' => ['jira']];
    }

    /**
     * @return array<string, mixed>
     */
    private function linearOnlyGlobal(): array
    {
        return ['WORK_ITEM_PROVIDERS' => ['linear']];
    }

    /**
     * @return array<string, mixed>
     */
    private function bothProvidersGlobal(): array
    {
        return ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']];
    }

    /**
     * @return array<string, mixed>
     */
    private function jiraCredentialsGlobal(): array
    {
        return [
            'WORK_ITEM_PROVIDERS' => ['jira'],
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
        ];
    }
}
