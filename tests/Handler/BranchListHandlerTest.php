<?php

namespace App\Tests\Handler;

use App\Handler\BranchListHandler;
use App\Service\GithubProvider;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class BranchListHandlerTest extends CommandTestCase
{
    private BranchListHandler $handler;
    private GithubProvider&MockObject $githubProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubProvider = $this->createMock(GithubProvider::class);
        $logger = $this->createMock(Logger::class);
        $this->handler = new BranchListHandler($this->gitRepository, $this->githubProvider, 'origin/develop', $this->translationService, $logger);
    }

    public function testHandleWithNoBranches(): void
    {
        $this->gitRepository->method('getAllLocalBranches')->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranches(): void
    {
        $branches = ['develop', 'feat/PROJ-123', 'main'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['develop', 'main']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitRepository->method('isBranchMergedInto')->willReturnCallback(function ($branch, $base) {
            return $branch === 'feat/PROJ-123' && $base === 'origin/develop';
        });
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithBranchHavingPr(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('getAllPullRequests')->willReturn([
            [
                'number' => 123,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithGithubProviderNull(): void
    {
        $logger = $this->createMock(Logger::class);
        $handler = new BranchListHandler($this->gitRepository, null, 'origin/develop', $this->translationService, $logger);

        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithGithubProviderException(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('getAllPullRequests')->willThrowException(new \Exception('API error'));
        $this->githubProvider->method('findPullRequestByBranchName')->willThrowException(new \Exception('API error'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithMergedBranchOnRemote(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithStaleBranch(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithPrMapOptimization(): void
    {
        $branches = ['feat/PROJ-123', 'feat/PROJ-456'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123', 'feat/PROJ-456']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false);

        // Mock getAllPullRequests to return PRs for both branches
        $allPrs = [
            [
                'number' => 123,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 456,
                'state' => 'closed',
                'head' => [
                    'ref' => 'feat/PROJ-456',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);
        // Should not call findPullRequestByBranchName when PR map is used
        $this->githubProvider->expects($this->never())->method('findPullRequestByBranchName');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleWithPrMapFallbackOnError(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitRepository->method('getAllLocalBranches')->willReturn($branches);
        $this->gitRepository->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitRepository->method('isBranchMergedInto')->willReturn(false);

        // getAllPullRequests fails, should fall back to per-branch calls
        $this->githubProvider->method('getAllPullRequests')->willThrowException(new \Exception('API error'));
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testBuildPrMapExcludesForkPrs(): void
    {
        $allPrs = [
            [
                'number' => 123,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 456,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-456',
                    'repo' => ['full_name' => 'fork_owner/test_repo'], // Fork PR
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildPrMap');
        $method->setAccessible(true);

        $prMap = $method->invoke($this->handler);

        // Should only include PR from same repo, exclude fork PR
        $this->assertCount(1, $prMap);
        $this->assertArrayHasKey('feat/PROJ-123', $prMap);
        $this->assertArrayNotHasKey('feat/PROJ-456', $prMap);
    }

    public function testBuildPrMapSkipsPrsWithoutHeadRef(): void
    {
        $allPrs = [
            [
                'number' => 123,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 456,
                'state' => 'open',
                'head' => [], // Missing ref
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildPrMap');
        $method->setAccessible(true);

        $prMap = $method->invoke($this->handler);

        // Should only include PR with head.ref
        $this->assertCount(1, $prMap);
        $this->assertArrayHasKey('feat/PROJ-123', $prMap);
    }

    public function testBuildPrMapHandlesException(): void
    {
        // Test that buildPrMap handles exceptions gracefully
        $this->githubProvider->method('getAllPullRequests')->willThrowException(new \Exception('API error'));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildPrMap');
        $method->setAccessible(true);

        $prMap = $method->invoke($this->handler);

        // Should return empty map on exception
        $this->assertSame([], $prMap);
    }

    public function testBuildPrMapSkipsPrsWithMissingRepoInfo(): void
    {
        $allPrs = [
            [
                'number' => 123,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-123',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 456,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-456',
                    // Missing repo info
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
            [
                'number' => 789,
                'state' => 'open',
                'head' => [
                    'ref' => 'feat/PROJ-789',
                    'repo' => ['full_name' => 'test_owner/test_repo'],
                ],
                // Missing base repo info
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildPrMap');
        $method->setAccessible(true);

        $prMap = $method->invoke($this->handler);

        // Should only include PR with complete repo info
        $this->assertCount(1, $prMap);
        $this->assertArrayHasKey('feat/PROJ-123', $prMap);
        $this->assertArrayNotHasKey('feat/PROJ-456', $prMap);
        $this->assertArrayNotHasKey('feat/PROJ-789', $prMap);
    }

    public function testHasPullRequestWithNullGithubProvider(): void
    {
        $logger = $this->createMock(Logger::class);
        $handler = new BranchListHandler($this->gitRepository, null, 'origin/develop', $this->translationService, $logger);

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('hasPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($handler, 'feat/PROJ-123', null);

        $this->assertFalse($result);
    }

    public function testHasPullRequestWithFallbackPath(): void
    {
        // Test hasPullRequest when prMap is null (fallback to per-branch API call)
        $pr = [
            'number' => 123,
            'state' => 'open',
            'head' => ['ref' => 'feat/PROJ-123'],
        ];

        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willReturn($pr);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', null);

        $this->assertTrue($result);
    }

    public function testHasPullRequestWithFallbackPathNoPr(): void
    {
        // Test hasPullRequest fallback when no PR is found
        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willReturn(null);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', null);

        $this->assertFalse($result);
    }

    public function testHasPullRequestWithFallbackPathException(): void
    {
        // Test hasPullRequest fallback when API call throws exception
        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willThrowException(new \Exception('API error'));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', null);

        $this->assertFalse($result);
    }

    public function testHasPullRequestWithPrMapFound(): void
    {
        // Test hasPullRequest with PR map when PR is found
        $prMap = [
            'feat/PROJ-123' => [
                'number' => 123,
                'state' => 'open',
                'head' => ['ref' => 'feat/PROJ-123'],
            ],
        ];

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', $prMap);

        $this->assertTrue($result);
    }

    public function testHasPullRequestWithPrMapNotFound(): void
    {
        // Test hasPullRequest with PR map when PR is not found
        $prMap = [
            'feat/PROJ-456' => [
                'number' => 456,
                'state' => 'open',
                'head' => ['ref' => 'feat/PROJ-456'],
            ],
        ];

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasPullRequest');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'feat/PROJ-123', $prMap);

        $this->assertFalse($result);
    }
}
