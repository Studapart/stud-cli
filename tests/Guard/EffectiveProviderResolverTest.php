<?php

declare(strict_types=1);

namespace App\Tests\Guard;

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
            ['workItemProvider' => 'jira'],
        );

        $this->assertSame(['jira'], $result['providers']);
        $this->assertFalse($result['ambiguous']);
    }

    public function testResolveWorkItemProvidersMarksAmbiguousAutoSelection(): void
    {
        $result = $this->resolver->resolveWorkItemProviders(
            [
                'WORK_ITEM_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://example.atlassian.net',
                'LINEAR_API_KEY' => 'lin',
            ],
            ['workItemProvider' => 'auto'],
        );

        $this->assertTrue($result['ambiguous']);
        $this->assertSame(['jira', 'linear'], $result['providers']);
    }

    public function testResolveWorkItemProvidersWithoutProjectConfigUsesGlobalList(): void
    {
        $result = $this->resolver->resolveWorkItemProviders(
            ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            null,
        );

        $this->assertFalse($result['ambiguous']);
        $this->assertSame(['linear'], $result['providers']);
    }
}
