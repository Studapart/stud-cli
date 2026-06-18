<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\GitProvider;
use App\Enum\WorkItemProvider;
use App\Service\GlobalConfigProviderResolver;
use PHPUnit\Framework\TestCase;

class GlobalConfigProviderResolverTest extends TestCase
{
    private GlobalConfigProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new GlobalConfigProviderResolver();
    }

    public function testNormalizeGitProvidersFiltersUnknownAndDedupes(): void
    {
        $this->assertSame(
            [GitProvider::Github->value, GitProvider::Gitlab->value],
            $this->resolver->normalizeGitProviders(['GitHub', 'gitlab', 'gitlab', 'invalid'])
        );
    }

    public function testNormalizeWorkItemProvidersFiltersUnknownAndDedupes(): void
    {
        $this->assertSame(
            [WorkItemProvider::Jira->value, WorkItemProvider::Linear->value],
            $this->resolver->normalizeWorkItemProviders(['JIRA', 'linear', 'nope'])
        );
    }

    public function testInferDefaultGitProvidersFromStoredTokens(): void
    {
        $this->assertSame(
            [GitProvider::Github->value],
            $this->resolver->inferDefaultGitProviders(['GITHUB_TOKEN' => 'gh'])
        );
        $this->assertSame(
            [GitProvider::Gitlab->value],
            $this->resolver->inferDefaultGitProviders(['GITLAB_TOKEN' => 'gl'])
        );
        $this->assertSame(
            [GitProvider::Github->value, GitProvider::Gitlab->value],
            $this->resolver->inferDefaultGitProviders(['GITHUB_TOKEN' => 'gh', 'GITLAB_TOKEN' => 'gl'])
        );
    }

    public function testInferDefaultWorkItemProvidersFromStoredCredentials(): void
    {
        $this->assertSame(
            [WorkItemProvider::Jira->value],
            $this->resolver->inferDefaultWorkItemProviders(['JIRA_URL' => 'https://jira.example.com'])
        );
        $this->assertSame(
            [WorkItemProvider::Linear->value],
            $this->resolver->inferDefaultWorkItemProviders(['LINEAR_API_KEY' => 'lin'])
        );
        $this->assertSame(
            [WorkItemProvider::Jira->value, WorkItemProvider::Linear->value],
            $this->resolver->inferDefaultWorkItemProviders([
                'JIRA_URL' => 'https://jira.example.com',
                'LINEAR_API_KEY' => 'lin',
            ])
        );
    }

    public function testCollectsProviderFlags(): void
    {
        $git = [GitProvider::Github->value, GitProvider::Gitlab->value];
        $work = [WorkItemProvider::Jira->value, WorkItemProvider::Linear->value];

        $this->assertTrue($this->resolver->collectsGithub($git));
        $this->assertTrue($this->resolver->collectsGitlab($git));
        $this->assertTrue($this->resolver->collectsJira($work));
        $this->assertTrue($this->resolver->collectsLinear($work));
        $this->assertFalse($this->resolver->collectsGithub([GitProvider::Gitlab->value]));
        $this->assertFalse($this->resolver->collectsLinear([WorkItemProvider::Jira->value]));
    }
}
