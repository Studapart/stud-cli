<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitBaseBranchResolver;
use PHPUnit\Framework\TestCase;

class GitBaseBranchResolverTest extends TestCase
{
    private GitBaseBranchResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new GitBaseBranchResolver();
    }

    public function testResolveLatestBaseBranchKeepsOriginPrefix(): void
    {
        $this->assertSame('origin/develop', $this->resolver->resolveLatestBaseBranch('origin/develop'));
    }

    public function testResolveLatestBaseBranchAddsOriginPrefix(): void
    {
        $this->assertSame('origin/develop', $this->resolver->resolveLatestBaseBranch('develop'));
    }

    public function testResolveLatestBaseBranchAddsOriginPrefixForMain(): void
    {
        $this->assertSame('origin/main', $this->resolver->resolveLatestBaseBranch('main'));
    }
}
