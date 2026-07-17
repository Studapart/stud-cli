<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\DTO\StateChange;
use App\Enum\IssueTrackerProvider;
use App\Service\IssueTrackerFactory;
use App\Service\IssueTrackerPortSupplier;
use App\Service\IssueTrackerResolver;
use App\Service\JiraApiClient;
use App\Service\JiraAttachmentService;
use App\Service\LinearApiClient;
use App\Service\LinearIssueTrackerAdapter;
use PHPUnit\Framework\TestCase;

class IssueTrackerPortSupplierTest extends TestCase
{
    public function testResolveReturnsPortForJira(): void
    {
        $jira = $this->createMock(JiraApiClient::class);
        $attachments = $this->createMock(JiraAttachmentService::class);
        $jira->method('getProjectTransitions')->willReturn([]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $jira,
            $attachments,
            null,
        );

        $result = $supplier->resolve(
            [
                'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
            ],
            [],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(IssueTrackerProvider::Jira->value, $result['provider']);
        $this->assertSame([], $result['port']->listProjectStateChanges('SCI'));
    }

    public function testResolveReturnsLinearDiscoveryPort(): void
    {
        $linear = $this->createMock(LinearApiClient::class);
        $linear->method('getTeamWorkflowStates')->willReturn([
            ['id' => 's1', 'name' => 'Todo', 'type' => 'unstarted'],
        ]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            null,
            null,
            $linear,
        );

        $result = $supplier->resolve(
            ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            [],
        );

        $this->assertTrue($result['ok']);
        $this->assertInstanceOf(LinearIssueTrackerAdapter::class, $result['port']);
        $this->assertInstanceOf(\App\Service\IssueTrackerLabelGroupsCapable::class, $result['port']);
        $this->assertEquals(
            [new StateChange('s1', 'Todo', null, 'unstarted')],
            $result['port']->listProjectStateChanges('SCI'),
        );
    }

    public function testResolveReturnsErrorWhenProviderResolutionFails(): void
    {
        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $this->createMock(JiraApiClient::class),
            $this->createMock(JiraAttachmentService::class),
            null,
        );

        $result = $supplier->resolve(
            ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value]],
            [],
        );

        $this->assertFalse($result['ok']);
        $this->assertInstanceOf(MessageRef::class, $result['error']);
    }

    public function testResolveReturnsErrorWhenLinearClientMissing(): void
    {
        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            null,
            null,
            null,
        );

        $result = $supplier->resolve(
            ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            [],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('issue_tracker_provider.missing_linear_api_key', $result['error']->key);
    }

    public function testResolveForProviderReturnsJiraPort(): void
    {
        $jira = $this->createMock(JiraApiClient::class);
        $jira->method('getProjectTransitions')->willReturn([]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $jira,
            $this->createMock(JiraAttachmentService::class),
            null,
        );

        $result = $supplier->resolveForProvider(
            IssueTrackerProvider::Jira->value,
            [
                'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
            ],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(IssueTrackerProvider::Jira->value, $result['provider']);
    }

    public function testResolveForDiscoveryInfersLinearFromScopeKey(): void
    {
        $linear = $this->createMock(LinearApiClient::class);
        $linear->method('getTeamWorkflowStates')->willReturn([]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $this->createMock(JiraApiClient::class),
            $this->createMock(JiraAttachmentService::class),
            $linear,
        );

        $result = $supplier->resolveForDiscovery(
            'ENG',
            [
                'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            [
                'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
                'projectKey' => 'SCI',
                'linearTeamKey' => 'ENG',
            ],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(IssueTrackerProvider::Linear->value, $result['provider']);
    }

    public function testResolveForDiscoveryReturnsAmbiguousWhenScopeMatchesBoth(): void
    {
        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $this->createMock(JiraApiClient::class),
            $this->createMock(JiraAttachmentService::class),
            $this->createMock(LinearApiClient::class),
        );

        $result = $supplier->resolveForDiscovery(
            'SCI',
            [
                'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            [
                'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
                'projectKey' => 'SCI',
            ],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('issue_tracker_provider.ambiguous_prefix', $result['error']->key);
    }

    public function testResolveForProviderReturnsErrorForInvalidProvider(): void
    {
        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $this->createMock(JiraApiClient::class),
            $this->createMock(JiraAttachmentService::class),
            null,
        );

        $result = $supplier->resolveForProvider('invalid', []);

        $this->assertFalse($result['ok']);
        $this->assertSame('issue_tracker_provider.invalid_override', $result['error']->key);
    }

    public function testResolveForDiscoveryUsesExplicitProjectProvider(): void
    {
        $linear = $this->createMock(LinearApiClient::class);
        $linear->method('getTeamWorkflowStates')->willReturn([]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $this->createMock(JiraApiClient::class),
            $this->createMock(JiraAttachmentService::class),
            $linear,
        );

        $result = $supplier->resolveForDiscovery(
            'SCI',
            [
                'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['issueTrackerProvider' => IssueTrackerProvider::Linear->value],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(IssueTrackerProvider::Linear->value, $result['provider']);
    }

    public function testResolveForDiscoveryFallsBackToResolveWhenSingleProvider(): void
    {
        $jira = $this->createMock(JiraApiClient::class);
        $jira->method('getProjectTransitions')->willReturn([]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $jira,
            $this->createMock(JiraAttachmentService::class),
            null,
        );

        $result = $supplier->resolveForDiscovery(
            'SCI',
            [
                'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
            ],
            ['issueTrackerProvider' => IssueTrackerProvider::Auto->value, 'projectKey' => 'SCI'],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(IssueTrackerProvider::Jira->value, $result['provider']);
    }

    public function testResolveForProviderReturnsErrorForAuto(): void
    {
        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            null,
            null,
            null,
        );

        $result = $supplier->resolveForProvider('auto', []);

        $this->assertFalse($result['ok']);
        $this->assertSame('issue_tracker_provider.invalid_override', $result['error']->key);
    }

    public function testResolveForDiscoveryFallsBackToResolveWhenDualPmNotFullyConfigured(): void
    {
        $jira = $this->createMock(JiraApiClient::class);
        $jira->method('getProjectTransitions')->willReturn([]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $jira,
            $this->createMock(JiraAttachmentService::class),
            null,
        );

        $result = $supplier->resolveForDiscovery(
            'ENG',
            [
                'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
            ],
            [
                'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
                'projectKey' => 'SCI',
                'linearTeamKey' => 'ENG',
            ],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(IssueTrackerProvider::Jira->value, $result['provider']);
    }

    public function testResolveForDiscoveryReturnsUnknownScopeWhenNoMatch(): void
    {
        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $this->createMock(JiraApiClient::class),
            $this->createMock(JiraAttachmentService::class),
            $this->createMock(LinearApiClient::class),
        );

        $result = $supplier->resolveForDiscovery(
            'XYZ',
            [
                'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            [
                'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
                'projectKey' => 'SCI',
                'linearTeamKey' => 'ENG',
            ],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('issue_tracker_provider.unknown_prefix', $result['error']->key);
    }

    public function testBuildPortResultReturnsErrorForUnknownProvider(): void
    {
        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $this->createMock(JiraApiClient::class),
            $this->createMock(JiraAttachmentService::class),
            null,
        );

        $method = new \ReflectionMethod(IssueTrackerPortSupplier::class, 'buildPortResult');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($supplier, 'unknown-vendor', []);

        $this->assertFalse($result['ok']);
        $this->assertSame('issue_tracker_provider.unknown_resolved', $result['error']->key);
    }

    public function testResolveForProviderReturnsErrorWhenCredentialsMissing(): void
    {
        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $this->createMock(JiraApiClient::class),
            $this->createMock(JiraAttachmentService::class),
            null,
        );

        $result = $supplier->resolveForProvider(
            IssueTrackerProvider::Jira->value,
            ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value]],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('issue_tracker_provider.missing_jira_configuration', $result['error']->key);
    }

    public function testResolveForDiscoveryIgnoresInvalidExplicitProjectProvider(): void
    {
        $jira = $this->createMock(JiraApiClient::class);
        $jira->method('getProjectTransitions')->willReturn([]);

        $supplier = new IssueTrackerPortSupplier(
            new IssueTrackerFactory(),
            new IssueTrackerResolver(),
            $jira,
            $this->createMock(JiraAttachmentService::class),
            null,
        );

        $result = $supplier->resolveForDiscovery(
            'SCI',
            [
                'ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
            ],
            [
                'issueTrackerProvider' => 'invalid',
                'projectKey' => 'SCI',
            ],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(IssueTrackerProvider::Jira->value, $result['provider']);
    }
}
