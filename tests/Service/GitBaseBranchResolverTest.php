<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitBaseBranchResolver;
use App\Service\GitRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GitBaseBranchResolverTest extends TestCase
{
    private GitBaseBranchResolver $resolver;
    private GitRepository&MockObject $gitRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->resolver = new GitBaseBranchResolver($this->gitRepository);
    }

    private function createSuccessProcess(string $output = ''): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($output);

        return $process;
    }

    private function createFailedProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        return $process;
    }

    public function testResolveLatestBaseBranchRemoteAheadOfLocal(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (array $cmd) {
                $line = implode(' ', $cmd);
                if ($line === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createFailedProcess();
            });

        $result = $this->resolver->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchLocalAheadOfRemote(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (array $cmd) {
                $line = implode(' ', $cmd);
                if ($line === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createFailedProcess();
                }

                return $this->createSuccessProcess();
            });

        $result = $this->resolver->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('develop', $result);
    }

    public function testResolveLatestBaseBranchSameCommitReturnsOriginal(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (array $cmd) {
                $line = implode(' ', $cmd);
                if ($line === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createSuccessProcess();
            });

        $result = $this->resolver->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchOnlyRemoteExists(): void
    {
        $this->gitRepository->expects($this->exactly(2))
            ->method('runQuietly')
            ->willReturnCallback(function (array $cmd) {
                $line = implode(' ', $cmd);
                if ($line === 'git rev-parse --verify --quiet develop') {
                    return $this->createFailedProcess();
                }

                return $this->createSuccessProcess();
            });

        $result = $this->resolver->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchOnlyLocalExists(): void
    {
        $this->gitRepository->expects($this->exactly(2))
            ->method('runQuietly')
            ->willReturnCallback(function (array $cmd) {
                $line = implode(' ', $cmd);
                if ($line === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createFailedProcess();
            });

        $result = $this->resolver->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('develop', $result);
    }

    public function testResolveLatestBaseBranchNeitherExistsReturnsOriginal(): void
    {
        $this->gitRepository->expects($this->exactly(2))
            ->method('runQuietly')
            ->willReturn($this->createFailedProcess());

        $result = $this->resolver->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchDivergedPrefersRemote(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (array $cmd) {
                $line = implode(' ', $cmd);
                if ($line === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createFailedProcess();
            });

        $result = $this->resolver->resolveLatestBaseBranch('origin/develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchWithoutOriginPrefix(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (array $cmd) {
                $line = implode(' ', $cmd);
                if ($line === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createSuccessProcess();
                }

                return $this->createFailedProcess();
            });

        $result = $this->resolver->resolveLatestBaseBranch('develop');

        $this->assertSame('origin/develop', $result);
    }

    public function testResolveLatestBaseBranchWithoutOriginPrefixLocalAhead(): void
    {
        $this->gitRepository->expects($this->exactly(4))
            ->method('runQuietly')
            ->willReturnCallback(function (array $cmd) {
                $line = implode(' ', $cmd);
                if ($line === 'git rev-parse --verify --quiet develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git rev-parse --verify --quiet origin/develop') {
                    return $this->createSuccessProcess();
                }
                if ($line === 'git merge-base --is-ancestor develop origin/develop') {
                    return $this->createFailedProcess();
                }

                return $this->createSuccessProcess();
            });

        $result = $this->resolver->resolveLatestBaseBranch('develop');

        $this->assertSame('develop', $result);
    }
}
