<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\WorkItemProvider;
use App\Service\GlobalConfigProviderResolver;
use App\Service\IssueTrackerFactory;
use App\Service\IssueTrackerResolver;
use PHPUnit\Framework\TestCase;

class IssueTrackerResolverTest extends TestCase
{
    private IssueTrackerResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new IssueTrackerResolver(new GlobalConfigProviderResolver());
    }

    public function testResolvesJiraWhenOnlyJiraConfigured(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => [WorkItemProvider::Jira->value]],
            [],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(WorkItemProvider::Jira->value, $result['provider']);
    }

    public function testResolvesLinearWhenOnlyLinearConfigured(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => [WorkItemProvider::Linear->value]],
            [],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(WorkItemProvider::Linear->value, $result['provider']);
    }

    public function testResolvesProjectOverrideWhenBothConfigured(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => [WorkItemProvider::Jira->value, WorkItemProvider::Linear->value]],
            ['workItemProvider' => WorkItemProvider::Linear->value],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(WorkItemProvider::Linear->value, $result['provider']);
    }

    public function testResolvesJiraOverrideWhenBothConfigured(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => [WorkItemProvider::Jira->value, WorkItemProvider::Linear->value]],
            ['workItemProvider' => WorkItemProvider::Jira->value],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(WorkItemProvider::Jira->value, $result['provider']);
    }

    public function testResolvesJiraByDefaultWhenBothConfiguredAndAuto(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            [
                'WORK_ITEM_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['workItemProvider' => WorkItemProvider::PROJECT_AUTO],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(WorkItemProvider::Jira->value, $result['provider']);
    }

    public function testResolvesLinearByDefaultWhenBothConfiguredWithoutJiraCredentials(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            [
                'WORK_ITEM_PROVIDERS' => ['jira', 'linear'],
                'LINEAR_API_KEY' => 'lin',
            ],
            ['workItemProvider' => WorkItemProvider::PROJECT_AUTO],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(WorkItemProvider::Linear->value, $result['provider']);
    }

    public function testReturnsErrorWhenBothConfiguredAndAutoWithoutCredentials(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => [WorkItemProvider::Jira->value, WorkItemProvider::Linear->value]],
            ['workItemProvider' => WorkItemProvider::PROJECT_AUTO],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'work_item_provider.not_configured',
            $result['error']->key,
        );
    }

    public function testReturnsErrorWhenNoProviderConfigured(): void
    {
        $globalResolver = $this->createMock(GlobalConfigProviderResolver::class);
        $globalResolver->method('resolveWorkItemProviders')->willReturn([]);
        $globalResolver->method('collectsJira')->willReturn(false);
        $globalResolver->method('collectsLinear')->willReturn(false);

        $resolver = new IssueTrackerResolver($globalResolver);
        $result = $resolver->resolveActiveProvider([], []);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'work_item_provider.not_configured',
            $result['error']->key,
        );
    }

    public function testReturnsErrorWhenResolvedSlugIsNotAWorkItemProvider(): void
    {
        $factory = $this->createMock(IssueTrackerFactory::class);
        $factory->expects($this->once())
            ->method('resolveType')
            ->with(null, [], [])
            ->willReturn('unknown');

        $resolver = new IssueTrackerResolver(new GlobalConfigProviderResolver(), $factory);
        $result = $resolver->resolveActiveProvider([], []);

        $this->assertFalse($result['ok']);
        $this->assertSame('work_item_provider.not_configured', $result['error']->key);
    }
}
