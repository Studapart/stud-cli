<?php

declare(strict_types=1);

namespace App\Tests\Guard\Resolver;

use App\Guard\Resolver\ProviderContextResolver;
use App\Service\GlobalConfigProviderResolver;
use PHPUnit\Framework\TestCase;

class ProviderContextResolverTest extends TestCase
{
    public function testResolveReturnsWorkItemAndGitProviders(): void
    {
        $inner = new GlobalConfigProviderResolver();
        $resolver = new ProviderContextResolver($inner);

        $result = $resolver->resolve([
            'WORK_ITEM_PROVIDERS' => ['jira'],
            'GIT_PROVIDERS' => ['github'],
            'JIRA_URL' => 'https://example.atlassian.net',
            'GITHUB_TOKEN' => 'token',
        ]);

        $this->assertSame(['jira'], $result['workItem']);
        $this->assertSame(['github'], $result['git']);
        $this->assertSame($inner, $resolver->providerResolver());
    }
}
