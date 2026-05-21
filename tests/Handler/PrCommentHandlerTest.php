<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\PrCommentRequest;
use App\DTO\PullRequestFeedbackActions;
use App\DTO\PullRequestFeedbackComment;
use App\DTO\PullRequestFeedbackConversation;
use App\DTO\PullRequestFeedbackIds;
use App\DTO\PullRequestFeedbackState;
use App\Handler\PrCommentHandler;
use App\Service\GitProviderInterface;
use App\Service\PullRequestFeedbackTargetResolver;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;

class PrCommentHandlerTest extends CommandTestCase
{
    private PrCommentHandler $handler;
    private GitProviderInterface $gitProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitProvider = $this->createMock(GitProviderInterface::class);
        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new PrCommentHandler(
            $this->gitRepository,
            $this->gitProvider,
            $this->translationService,
        );
    }

    public function testHandlePostsTopLevelComment(): void
    {
        $this->mockActivePullRequest(123);

        $this->gitProvider
            ->expects($this->once())
            ->method('createComment')
            ->with(123, 'My comment message')
            ->willReturn(['id' => 456]);

        $response = $this->handler->handle(new PrCommentRequest('My comment message'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('posted', $response->action);
        $this->assertSame(123, $response->pullNumber);
        $this->assertNull($response->target);
        $this->assertFalse($response->resolved);
    }

    public function testHandleUnescapesCheckboxMarkdownBeforePosting(): void
    {
        $this->mockActivePullRequest(123);

        $this->gitProvider
            ->expects($this->once())
            ->method('createComment')
            ->with(123, "- [ ] Unchecked\n- [x] Checked")
            ->willReturn(['id' => 456]);

        $response = $this->handler->handle(new PrCommentRequest("- \\[ \\] Unchecked\n- \\[x\\] Checked"));

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleFailsWhenProviderIsMissing(): void
    {
        $handler = new PrCommentHandler($this->gitRepository, null, $this->translationService);

        $response = $handler->handle(new PrCommentRequest('My comment'));

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleFailsWhenInputIsEmpty(): void
    {
        $this->gitProvider->expects($this->never())->method('findPullRequestByBranch');

        $response = $this->handler->handle(new PrCommentRequest('   '));

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleFailsWhenNoPullRequestIsFound(): void
    {
        $this->mockActivePullRequest(null);
        $this->gitProvider->expects($this->never())->method('createComment');

        $response = $this->handler->handle(new PrCommentRequest('My comment'));

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleFailsWhenTopLevelCommentPostFails(): void
    {
        $this->mockActivePullRequest(123);

        $this->gitProvider
            ->expects($this->once())
            ->method('createComment')
            ->willThrowException(new \RuntimeException('API Error'));

        $response = $this->handler->handle(new PrCommentRequest('My comment'));

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleRepliesToFeedbackTarget(): void
    {
        $conversation = $this->replyableConversation(canResolve: true);
        $this->mockActivePullRequest(123);
        $this->gitProvider->method('getPullRequestFeedbackConversations')->willReturn([$conversation]);
        $this->gitProvider
            ->expects($this->once())
            ->method('replyToPullRequestFeedback')
            ->with(123, $conversation->ids, 'Thanks, fixed')
            ->willReturn(['id' => 'reply']);
        $this->gitProvider->expects($this->never())->method('resolvePullRequestFeedback');

        $response = $this->handler->handle(new PrCommentRequest('Thanks, fixed', $conversation->ids->target));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('replied', $response->action);
        $this->assertSame($conversation->ids->target, $response->target);
        $this->assertFalse($response->resolved);
    }

    public function testHandleRepliesThenResolvesTarget(): void
    {
        $conversation = $this->replyableConversation(canResolve: true);
        $calls = [];
        $this->mockActivePullRequest(123);
        $this->gitProvider->method('getPullRequestFeedbackConversations')->willReturn([$conversation]);
        $this->gitProvider->method('replyToPullRequestFeedback')->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'reply';

            return ['id' => 'reply'];
        });
        $this->gitProvider->method('resolvePullRequestFeedback')->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'resolve';

            return ['id' => 'thread', 'isResolved' => true];
        });

        $response = $this->handler->handle(new PrCommentRequest('Thanks, fixed', $conversation->ids->target, true));

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->resolved);
        $this->assertSame(['reply', 'resolve'], $calls);
    }

    public function testHandleDoesNotResolveWhenReplyFails(): void
    {
        $conversation = $this->replyableConversation(canResolve: true);
        $this->mockActivePullRequest(123);
        $this->gitProvider->method('getPullRequestFeedbackConversations')->willReturn([$conversation]);
        $this->gitProvider->method('replyToPullRequestFeedback')->willThrowException(new \RuntimeException('reply failed'));
        $this->gitProvider->expects($this->never())->method('resolvePullRequestFeedback');

        $response = $this->handler->handle(new PrCommentRequest('Thanks, fixed', $conversation->ids->target, true));

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReportsResolveFailureAfterReply(): void
    {
        $conversation = $this->replyableConversation(canResolve: true);
        $this->mockActivePullRequest(123);
        $this->gitProvider->method('getPullRequestFeedbackConversations')->willReturn([$conversation]);
        $this->gitProvider->method('replyToPullRequestFeedback')->willReturn(['id' => 'reply']);
        $this->gitProvider->method('resolvePullRequestFeedback')->willThrowException(new \RuntimeException('resolve failed'));

        $response = $this->handler->handle(new PrCommentRequest('Thanks, fixed', $conversation->ids->target, true));

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleFailsWhenTargetIsInvalid(): void
    {
        $this->mockActivePullRequest(123);
        $this->gitProvider->method('getPullRequestFeedbackConversations')->willReturn([]);
        $this->gitProvider->expects($this->never())->method('replyToPullRequestFeedback');

        $response = $this->handler->handle(new PrCommentRequest('Thanks', 'invalid-target'));

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleFailsWhenTargetCannotReply(): void
    {
        $conversation = $this->replyableConversation(canReply: false);
        $this->mockActivePullRequest(123);
        $this->gitProvider->method('getPullRequestFeedbackConversations')->willReturn([$conversation]);

        $response = $this->handler->handle(new PrCommentRequest('Thanks', $conversation->ids->target));

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleFailsWhenTargetCannotResolve(): void
    {
        $conversation = $this->replyableConversation(canResolve: false);
        $this->mockActivePullRequest(123);
        $this->gitProvider->method('getPullRequestFeedbackConversations')->willReturn([$conversation]);

        $response = $this->handler->handle(new PrCommentRequest('Thanks', $conversation->ids->target, true));

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleFailsWhenTargetIsAlreadyResolved(): void
    {
        $conversation = $this->replyableConversation(canResolve: false, resolved: true);
        $this->mockActivePullRequest(123);
        $this->gitProvider->method('getPullRequestFeedbackConversations')->willReturn([$conversation]);

        $response = $this->handler->handle(new PrCommentRequest('Thanks', $conversation->ids->target, true));

        $this->assertFalse($response->isSuccess());
    }

    public function testPrepareCommentBodyReturnsNullForEmptyInput(): void
    {
        $result = $this->callPrivateMethod($this->handler, 'prepareCommentBody', ['']);

        $this->assertNull($result);
    }

    public function testFindActivePullRequestHandlesMissingNumber(): void
    {
        $this->mockActivePullRequest(['id' => 123]);

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', []);

        $this->assertNull($result);
    }

    public function testFindActivePullRequestHandlesProviderException(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/SCI-96');
        $this->gitRepository->method('getRepositoryOwner')->with('origin')->willReturn('studapart');
        $this->gitProvider->method('findPullRequestByBranch')->willThrowException(new \RuntimeException('API error'));

        $result = $this->callPrivateMethod($this->handler, 'findActivePullRequest', []);

        $this->assertNull($result);
    }

    private function mockActivePullRequest(null|int|array $pullRequest): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/SCI-96');
        $this->gitRepository->method('getRepositoryOwner')->with('origin')->willReturn('studapart');
        $this->gitProvider
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/SCI-96')
            ->willReturn(is_int($pullRequest) ? ['number' => $pullRequest] : $pullRequest);
    }

    private function replyableConversation(
        bool $canReply = true,
        bool $canResolve = false,
        bool $resolved = false,
    ): PullRequestFeedbackConversation {
        $baseIds = new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread-1');
        $target = (new PullRequestFeedbackTargetResolver())->targetForIds($baseIds);
        $ids = new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread-1', target: $target);

        return new PullRequestFeedbackConversation(
            $ids,
            'review_thread',
            new PullRequestFeedbackState(resolved: $resolved, resolvable: true),
            [
                new PullRequestFeedbackComment(
                    new PullRequestFeedbackIds('github', 'review_comment', threadId: 'thread-1'),
                    'alice',
                    new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                    'Please fix this',
                    new PullRequestFeedbackState(),
                    null,
                    new PullRequestFeedbackActions(canReply: $canReply),
                ),
            ],
            new PullRequestFeedbackActions(canReply: $canReply, canResolve: $canResolve),
        );
    }
}
