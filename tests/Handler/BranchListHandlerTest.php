<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\BranchListHandler;
use App\Service\GithubProvider;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class BranchListHandlerTest extends CommandTestCase
{
    private BranchListHandler $handler;
    private GithubProvider&MockObject $githubProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->githubProvider = $this->createMock(GithubProvider::class);
        $logger = $this->createMock(Logger::class);
        $this->handler = new BranchListHandler(
            $this->gitRepository,
            $this->gitBranchService,
            $this->githubProvider,
            'origin/develop',
            $this->translationService
        );
    }

    public function testHandleWithNoBranchesReturnsEmptyRows(): void
    {
        $this->gitBranchService->method('getAllLocalBranches')->willReturn([]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(0, $response->rows);
    }

    public function testHandleWithBranchesReturnsRows(): void
    {
        $branches = ['develop', 'feat/PROJ-123', 'main'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['develop', 'main']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('develop');
        $this->gitBranchService->method('isBranchMergedInto')->willReturnCallback(
            fn ($branch, $base) => $branch === 'feat/PROJ-123' && $base === 'origin/develop'
        );
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(3, $response->rows);
    }

    public function testHandleWithBranchHavingPr(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);
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

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->rows);
        $this->assertSame('✓', $response->rows[0]->pr);
    }

    public function testHandleWithGithubProviderNull(): void
    {
        $handler = new BranchListHandler($this->gitRepository, $this->gitBranchService, null, 'origin/develop', $this->translationService);

        $branches = ['feat/PROJ-123'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);

        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->rows);
        $this->assertSame('✗', $response->rows[0]->pr);
    }

    public function testHandleWithGithubProviderException(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('getAllPullRequests')->willThrowException(new \Exception('API error'));
        $this->githubProvider->method('findPullRequestByBranchName')->willThrowException(new \Exception('API error'));

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->rows);
    }

    public function testHandleWithMergedBranchOnRemote(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->rows);
        $this->assertStringContainsString('merged', $response->rows[0]->status);
    }

    public function testHandleWithStaleBranch(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(true);
        $this->githubProvider->method('getAllPullRequests')->willReturn([]);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->rows);
        $this->assertStringContainsString('stale', $response->rows[0]->status);
    }

    public function testHandleWithPrMapOptimization(): void
    {
        $branches = ['feat/PROJ-123', 'feat/PROJ-456'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123', 'feat/PROJ-456']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);

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
        $this->githubProvider->expects($this->never())->method('findPullRequestByBranchName');

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(2, $response->rows);
        $this->assertSame('✓', $response->rows[0]->pr);
        $this->assertSame('✓', $response->rows[1]->pr);
    }

    public function testHandleWithPrMapFallbackOnError(): void
    {
        $branches = ['feat/PROJ-123'];
        $this->gitBranchService->method('getAllLocalBranches')->willReturn($branches);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['feat/PROJ-123']);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('main');
        $this->gitBranchService->method('isBranchMergedInto')->willReturn(false);
        $this->githubProvider->method('getAllPullRequests')->willThrowException(new \Exception('API error'));
        $this->githubProvider->method('findPullRequestByBranchName')->willReturn(null);

        $response = $this->handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->rows);
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
                    'repo' => ['full_name' => 'fork_owner/test_repo'],
                ],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);

        $prMap = $this->callPrivateMethod($this->handler, 'buildPrMap', []);

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
                'head' => [],
                'base' => ['repo' => ['full_name' => 'test_owner/test_repo']],
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);

        $prMap = $this->callPrivateMethod($this->handler, 'buildPrMap', []);

        $this->assertCount(1, $prMap);
        $this->assertArrayHasKey('feat/PROJ-123', $prMap);
    }

    public function testBuildPrMapHandlesException(): void
    {
        $this->githubProvider->method('getAllPullRequests')->willThrowException(new \Exception('API error'));

        $prMap = $this->callPrivateMethod($this->handler, 'buildPrMap', []);

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
            ],
        ];

        $this->githubProvider->method('getAllPullRequests')->with('all')->willReturn($allPrs);

        $prMap = $this->callPrivateMethod($this->handler, 'buildPrMap', []);

        $this->assertCount(1, $prMap);
        $this->assertArrayHasKey('feat/PROJ-123', $prMap);
    }

    public function testHasPullRequestWithNullGithubProvider(): void
    {
        $handler = new BranchListHandler($this->gitRepository, $this->gitBranchService, null, 'origin/develop', $this->translationService);

        $result = $this->callPrivateMethod($handler, 'hasPullRequest', ['feat/PROJ-123', null]);

        $this->assertFalse($result);
    }

    public function testHasPullRequestWithFallbackPath(): void
    {
        $pr = [
            'number' => 123,
            'state' => 'open',
            'head' => ['ref' => 'feat/PROJ-123'],
        ];

        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willReturn($pr);

        $result = $this->callPrivateMethod($this->handler, 'hasPullRequest', ['feat/PROJ-123', null]);

        $this->assertTrue($result);
    }

    public function testHasPullRequestWithFallbackPathNoPr(): void
    {
        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willReturn(null);

        $result = $this->callPrivateMethod($this->handler, 'hasPullRequest', ['feat/PROJ-123', null]);

        $this->assertFalse($result);
    }

    public function testHasPullRequestWithFallbackPathException(): void
    {
        $this->githubProvider->method('findPullRequestByBranchName')
            ->with('feat/PROJ-123', 'all')
            ->willThrowException(new \Exception('API error'));

        $result = $this->callPrivateMethod($this->handler, 'hasPullRequest', ['feat/PROJ-123', null]);

        $this->assertFalse($result);
    }

    public function testHasPullRequestWithPrMapFound(): void
    {
        $prMap = [
            'feat/PROJ-123' => [
                'number' => 123,
                'state' => 'open',
                'head' => ['ref' => 'feat/PROJ-123'],
            ],
        ];

        $result = $this->callPrivateMethod($this->handler, 'hasPullRequest', ['feat/PROJ-123', $prMap]);

        $this->assertTrue($result);
    }

    public function testHasPullRequestWithPrMapNotFound(): void
    {
        $prMap = [
            'feat/PROJ-456' => [
                'number' => 456,
                'state' => 'open',
                'head' => ['ref' => 'feat/PROJ-456'],
            ],
        ];

        $result = $this->callPrivateMethod($this->handler, 'hasPullRequest', ['feat/PROJ-123', $prMap]);

        $this->assertFalse($result);
    }
}
