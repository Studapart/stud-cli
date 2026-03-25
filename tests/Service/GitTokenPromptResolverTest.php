<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitTokenPromptResolver;
use PHPUnit\Framework\TestCase;

class GitTokenPromptResolverTest extends TestCase
{
    private GitTokenPromptResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new GitTokenPromptResolver();
    }

    public function testTokenFromUserInputTrimsAndRejectsBlank(): void
    {
        $this->assertNull($this->resolver->tokenFromUserInput(null));
        $this->assertNull($this->resolver->tokenFromUserInput(''));
        $this->assertNull($this->resolver->tokenFromUserInput('   '));
        $this->assertSame('abc', $this->resolver->tokenFromUserInput('  abc  '));
    }

    public function testResolveForGlobalInitUsesUserInputWhenNonEmpty(): void
    {
        $result = $this->resolver->resolveForGlobalInit(
            'user_entered_token',
            'GITHUB_TOKEN',
            ['GIT_TOKEN' => 'legacy', 'GIT_PROVIDER' => 'github']
        );
        $this->assertSame('user_entered_token', $result);
    }

    public function testResolveForGlobalInitPreservesExistingNewKeyWhenPromptEmpty(): void
    {
        $result = $this->resolver->resolveForGlobalInit(
            null,
            'GITHUB_TOKEN',
            ['GITHUB_TOKEN' => 'existing_github']
        );
        $this->assertSame('existing_github', $result);
    }

    public function testResolveForGlobalInitPreservesLegacyTokenForGithubWhenProviderUnset(): void
    {
        $result = $this->resolver->resolveForGlobalInit(
            null,
            'GITHUB_TOKEN',
            ['GIT_TOKEN' => 'legacy_token']
        );
        $this->assertSame('legacy_token', $result);
    }

    public function testResolveForGlobalInitPreservesLegacyTokenForGithubWhenProviderGithub(): void
    {
        $result = $this->resolver->resolveForGlobalInit(
            null,
            'GITHUB_TOKEN',
            ['GIT_TOKEN' => 'legacy_token', 'GIT_PROVIDER' => 'github']
        );
        $this->assertSame('legacy_token', $result);
    }

    public function testResolveForGlobalInitReturnsNullForGithubWhenProviderGitlab(): void
    {
        $result = $this->resolver->resolveForGlobalInit(
            null,
            'GITHUB_TOKEN',
            ['GIT_TOKEN' => 'legacy_token', 'GIT_PROVIDER' => 'gitlab']
        );
        $this->assertNull($result);
    }

    public function testResolveForGlobalInitPreservesLegacyTokenForGitlabWhenProviderGitlab(): void
    {
        $result = $this->resolver->resolveForGlobalInit(
            null,
            'GITLAB_TOKEN',
            ['GIT_TOKEN' => 'legacy_token', 'GIT_PROVIDER' => 'gitlab']
        );
        $this->assertSame('legacy_token', $result);
    }

    public function testResolveForGlobalInitReturnsNullWhenNoExistingToken(): void
    {
        $result = $this->resolver->resolveForGlobalInit(
            null,
            'GITHUB_TOKEN',
            []
        );
        $this->assertNull($result);
    }
}
