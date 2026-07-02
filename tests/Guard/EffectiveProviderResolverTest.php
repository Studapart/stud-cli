<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Enum\WorkItemProvider;
use App\Guard\Resolver\EffectiveProviderResolver;
use PHPUnit\Framework\TestCase;

class EffectiveProviderResolverTest extends TestCase
{
    private EffectiveProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new EffectiveProviderResolver();
    }

    public function testResolveGitProvidersUsesResolvedProviderOverGlobalList(): void
    {
        $providers = $this->resolver->resolveGitProviders(
            ['GIT_PROVIDERS' => ['github', 'gitlab'], 'GITHUB_TOKEN' => 'gh'],
            ['gitProvider' => 'gitlab'],
            true,
            'github',
        );

        $this->assertSame(['github'], $providers);
    }

    public function testResolveGitProvidersUsesProjectProviderWhenResolvedIsNull(): void
    {
        $providers = $this->resolver->resolveGitProviders(
            ['GIT_PROVIDERS' => ['github', 'gitlab']],
            ['gitProvider' => 'github'],
            true,
            null,
        );

        $this->assertSame(['github'], $providers);
    }

    public function testResolveGitProvidersFallsBackToGlobalWhenNoProjectContext(): void
    {
        $providers = $this->resolver->resolveGitProviders(
            ['GIT_PROVIDERS' => ['github', 'gitlab']],
            null,
            false,
            null,
        );

        $this->assertSame(['github', 'gitlab'], $providers);
    }

    public function testResolveWorkItemProvidersUsesActiveProjectProvider(): void
    {
        $result = $this->resolver->resolveWorkItemProviders(
            [
                'WORK_ITEM_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['workItemProvider' => WorkItemProvider::Jira->value],
        );

        $this->assertSame([WorkItemProvider::Jira->value], $result['providers']);
        $this->assertFalse($result['ambiguous']);
    }

    public function testResolveWorkItemProvidersResolvesAutoToJiraWhenBothConfigured(): void
    {
        $result = $this->resolver->resolveWorkItemProviders(
            [
                'WORK_ITEM_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['workItemProvider' => WorkItemProvider::PROJECT_AUTO],
        );

        $this->assertFalse($result['ambiguous']);
        $this->assertSame([WorkItemProvider::Jira->value], $result['providers']);
    }

    public function testResolveWorkItemProvidersMarksAmbiguousWhenAutoCannotResolve(): void
    {
        $result = $this->resolver->resolveWorkItemProviders(
            ['WORK_ITEM_PROVIDERS' => [WorkItemProvider::Jira->value, WorkItemProvider::Linear->value]],
            ['workItemProvider' => WorkItemProvider::PROJECT_AUTO],
        );

        $this->assertTrue($result['ambiguous']);
        $this->assertSame([WorkItemProvider::Jira->value, WorkItemProvider::Linear->value], $result['providers']);
    }

    public function testResolveWorkItemProvidersWithoutProjectConfigUsesGlobalList(): void
    {
        $result = $this->resolver->resolveWorkItemProviders(
            ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            null,
        );

        $this->assertFalse($result['ambiguous']);
        $this->assertSame([WorkItemProvider::Linear->value], $result['providers']);
    }
}
