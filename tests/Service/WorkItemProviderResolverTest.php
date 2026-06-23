<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GlobalConfigProviderResolver;
use App\Service\WorkItemProviderResolver;
use PHPUnit\Framework\TestCase;

class WorkItemProviderResolverTest extends TestCase
{
    private WorkItemProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new WorkItemProviderResolver(new GlobalConfigProviderResolver());
    }

    public function testResolvesJiraWhenOnlyJiraConfigured(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => ['jira']],
            [],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('jira', $result['provider']);
    }

    public function testResolvesLinearWhenOnlyLinearConfigured(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => ['linear']],
            [],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('linear', $result['provider']);
    }

    public function testResolvesProjectOverrideWhenBothConfigured(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            ['workItemProvider' => 'linear'],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('linear', $result['provider']);
    }

    public function testResolvesJiraOverrideWhenBothConfigured(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            ['workItemProvider' => 'jira'],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('jira', $result['provider']);
    }

    public function testReturnsErrorWhenBothConfiguredAndAuto(): void
    {
        $result = $this->resolver->resolveActiveProvider(
            ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            [],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'project.workflow.error_ambiguous_provider',
            $result['error']->key,
        );
    }

    public function testReturnsErrorWhenNoProviderConfigured(): void
    {
        $globalResolver = $this->createMock(GlobalConfigProviderResolver::class);
        $globalResolver->method('resolveWorkItemProviders')->willReturn([]);
        $globalResolver->method('collectsJira')->willReturn(false);
        $globalResolver->method('collectsLinear')->willReturn(false);

        $resolver = new WorkItemProviderResolver($globalResolver);
        $result = $resolver->resolveActiveProvider([], []);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'project.workflow.error_no_provider',
            $result['error']->key,
        );
    }
}
