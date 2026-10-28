<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\MessageRef;
use App\DTO\PullRequestComment;
use App\DTO\PullRequestFeedbackActions;
use App\DTO\PullRequestFeedbackComment;
use App\DTO\PullRequestFeedbackConversation;
use App\DTO\PullRequestFeedbackIds;
use App\DTO\PullRequestFeedbackLocation;
use App\DTO\PullRequestFeedbackState;
use App\Enum\OutputFormat;
use App\Responder\PrCommentBodyRenderer;
use App\Responder\PrCommentsResponder;
use App\Response\PrCommentsResponse;
use App\Service\ColorHelper;
use App\Service\CommentBodyParser;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class PrCommentsResponderTest extends CommandTestCase
{
    private SymfonyStyle&\PHPUnit\Framework\MockObject\MockObject $io;

    private PrCommentsResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = new ResponderHelper($this->translationService);
        $this->io = $this->createMock(SymfonyStyle::class);
        $this->responder = new PrCommentsResponder($helper, new CommentBodyParser(), $this->createLogger($this->io));
    }

    /**
     * @param list<string> $calls
     */
    private function record(SymfonyStyle $io, string $method, array &$calls): void
    {
        $io->method($method)->willReturnCallback(function (string $text) use (&$calls): void {
            $calls[] = $text;
        });
    }

    /**
     * @param list<string> $calls
     */
    private function assertRecordedStartsWith(array $calls, string $prefix): void
    {
        $this->assertTrue(
            array_any($calls, static fn (string $call): bool => str_starts_with($call, $prefix)),
            $prefix,
        );
    }

    public function testRespondShowsNoteWhenNoIssueCommentsAndNoReviewComments(): void
    {
        $response = PrCommentsResponse::success([], [], [], 42);
        $sections = [];
        $notes = [];
        $this->record($this->io, 'section', $sections);
        $this->record($this->io, 'note', $notes);

        $this->responder->respond($this->io, $response);

        $this->assertSame([
            'pr.comments.section {"number":42}',
            'pr.comments.issue_comments',
            'pr.comments.reviews',
            'pr.comments.review_comments',
        ], $sections);
        $this->assertSame(array_fill(0, 3, 'pr.comments.no_comments'), $notes);
    }

    public function testRespondRendersSectionAndTextForIssueComments(): void
    {
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), 'Body text', null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $sections = [];
        $notes = [];
        $this->record($this->io, 'section', $sections);
        $this->record($this->io, 'note', $notes);
        $this->io->expects($this->once())->method('text')->with('Body text');

        $this->responder->respond($this->io, $response);

        $this->assertContains('pr.comments.section {"number":42}', $sections);
        $this->assertContains('pr.comments.issue_comments', $sections);
        $this->assertContains('pr.comments.reviews', $sections);
        $this->assertContains('pr.comments.review_comments', $sections);
        $this->assertRecordedStartsWith($sections, 'pr.comments.table.author: alice');
        $this->assertSame(array_fill(0, 2, 'pr.comments.no_comments'), $notes);
    }

    public function testRespondRendersSectionAndTextForReviewComments(): void
    {
        $comment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Review body', 'src/File.php', 10);
        $response = PrCommentsResponse::success([], [$comment], [], 42);

        $sections = [];
        $notes = [];
        $this->record($this->io, 'section', $sections);
        $this->record($this->io, 'note', $notes);
        $this->io->expects($this->once())->method('text')->with('Review body');

        $this->responder->respond($this->io, $response);

        $this->assertRecordedStartsWith($sections, 'pr.comments.table.author: bob');
        $this->assertTrue(array_any($sections, static fn (string $call): bool => str_contains($call, 'src/File.php:10')));
        $this->assertSame(array_fill(0, 2, 'pr.comments.no_comments'), $notes);
    }

    public function testRespondRendersSectionsAndTextWhenBothCommentTypesPresent(): void
    {
        $issueComment = new PullRequestComment('alice', new \DateTimeImmutable(), 'Issue', null, null);
        $reviewComment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Review', 'file.php', 5);
        $response = PrCommentsResponse::success([$issueComment], [$reviewComment], [], 42);

        $sections = [];
        $notes = [];
        $this->record($this->io, 'section', $sections);
        $this->record($this->io, 'note', $notes);
        $this->io->expects($this->exactly(2))->method('text');

        $this->responder->respond($this->io, $response);

        $this->assertRecordedStartsWith($sections, 'pr.comments.table.author: alice');
        $this->assertRecordedStartsWith($sections, 'pr.comments.table.author: bob');
        $this->assertSame(['pr.comments.no_comments'], $notes);
    }

    public function testRespondWithColorHelperFormatsSectionTitles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new PrCommentsResponder($helper, new CommentBodyParser(), $this->createLogger($io));
        $issueComment = new PullRequestComment('alice', new \DateTimeImmutable(), 'Body', null, null);
        $reviewComment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Review', 'file.php', 5);
        $response = PrCommentsResponse::success([$issueComment], [$reviewComment], [], 42);

        $colorHelper->expects($this->atLeastOnce())->method('registerStyles')->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->with($this->anything(), $this->anything())
            ->willReturnCallback(fn ($_, $text) => is_string($text) ? $text : '');

        $sections = [];
        $notes = [];
        $this->record($io, 'section', $sections);
        $this->record($io, 'note', $notes);
        $io->expects($this->exactly(2))->method('text');

        $responder->respond($io, $response);

        $this->assertRecordedStartsWith($sections, 'pr.comments.table.author: alice');
        $this->assertRecordedStartsWith($sections, 'pr.comments.table.author: bob');
        $this->assertSame(['pr.comments.no_comments'], $notes);
    }

    public function testRespondWithColorHelperFormatsListingItems(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $helper = new ResponderHelper($this->translationService, $colorHelper);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new PrCommentsResponder($helper, new CommentBodyParser(), $this->createLogger($io));
        $body = "Checklist:\n\n- one\n- two";
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $colorHelper->expects($this->atLeastOnce())->method('registerStyles')->with($io);
        $colorHelper->expects($this->atLeastOnce())
            ->method('format')
            ->with($this->anything(), $this->anything())
            ->willReturnCallback(fn ($_, $text) => is_string($text) ? $text : '');

        $sections = [];
        $notes = [];
        $this->record($io, 'section', $sections);
        $this->record($io, 'note', $notes);
        $io->expects($this->once())->method('text');
        $io->expects($this->once())->method('listing');

        $responder->respond($io, $response);

        $this->assertContains('pr.comments.issue_comments', $sections);
        $this->assertSame(array_fill(0, 2, 'pr.comments.no_comments'), $notes);
    }

    public function testRespondRendersTableBlockWhenCommentBodyContainsMarkdownTable(): void
    {
        $body = "Some intro.\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n\nAfter table.";
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $sections = [];
        $notes = [];
        $this->record($this->io, 'section', $sections);
        $this->record($this->io, 'note', $notes);
        $this->io->expects($this->once())->method('table');

        $this->responder->respond($this->io, $response);

        $this->assertContains('pr.comments.issue_comments', $sections);
        $this->assertSame(array_fill(0, 2, 'pr.comments.no_comments'), $notes);
    }

    public function testRespondRendersListingWhenCommentBodyContainsMarkdownList(): void
    {
        $body = "Items:\n\n- one\n- two\n- three";
        $comment = new PullRequestComment('bob', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $sections = [];
        $notes = [];
        $this->record($this->io, 'section', $sections);
        $this->record($this->io, 'note', $notes);
        $this->io->expects($this->once())->method('listing');

        $this->responder->respond($this->io, $response);

        $this->assertContains('pr.comments.issue_comments', $sections);
        $this->assertSame(array_fill(0, 2, 'pr.comments.no_comments'), $notes);
    }

    public function testRespondStripsBackticksFromCommentBodyForTerminalSafety(): void
    {
        $body = 'Run `ls -la` or use ```bash echo 1 ```';
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $sections = [];
        $notes = [];
        $this->record($this->io, 'section', $sections);
        $this->record($this->io, 'note', $notes);
        $this->io->expects($this->once())->method('text')->with($this->callback(function (string $text): bool {
            return ! str_contains($text, '`');
        }));

        $this->responder->respond($this->io, $response);

        $this->assertContains('pr.comments.issue_comments', $sections);
        $this->assertSame(array_fill(0, 2, 'pr.comments.no_comments'), $notes);
    }

    public function testRespondRendersReviewCommentWithPathButNullLine(): void
    {
        $comment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Comment', 'src/File.php', null);
        $response = PrCommentsResponse::success([], [$comment], [], 42);

        $sections = [];
        $this->record($this->io, 'section', $sections);

        $this->responder->respond($this->io, $response);

        $matched = array_values(array_filter(
            $sections,
            static fn (string $call): bool => str_contains($call, 'src/File.php'),
        ));
        $this->assertNotEmpty($matched);
        $this->assertStringNotContainsString('src/File.php:', $matched[0]);
    }

    public function testRespondRendersReviewsSectionWithReviewBodies(): void
    {
        $reviewComment = new PullRequestComment('reviewer', new \DateTimeImmutable(), 'Please fix the nits.', null, null);
        $response = PrCommentsResponse::success([], [], [$reviewComment], 42);

        $sections = [];
        $this->record($this->io, 'section', $sections);
        $this->io->expects($this->once())->method('text')->with('Please fix the nits.');

        $this->responder->respond($this->io, $response);

        $this->assertRecordedStartsWith($sections, 'pr.comments.table.author: reviewer');
    }

    public function testRenderTextSegmentWithEmptyContentDoesNotCallText(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->expects($this->never())->method('text');
        $renderer = new PrCommentBodyRenderer(new ResponderHelper($this->translationService), new CommentBodyParser(), $logger);

        $this->callPrivateMethod($renderer, 'renderTextSegment', [['content' => '']]);
    }

    public function testRenderListSegmentWithEmptyItemsDoesNotCallListing(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->expects($this->never())->method('listing');
        $renderer = new PrCommentBodyRenderer(new ResponderHelper($this->translationService), new CommentBodyParser(), $logger);

        $this->callPrivateMethod($renderer, 'renderListSegment', [['items' => []]]);
    }

    public function testRespondJsonReturnsSerializedComments(): void
    {
        $comment = new PullRequestComment('author', new \DateTimeImmutable(), 'body text');
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $payload = $result->toPayload();
        $this->assertAgentJsonSuccess($payload, ['pullNumber' => 42]);
        $this->assertCount(1, $payload['data']['issueComments']);
        $this->assertArrayNotHasKey('conversations', $payload['data']);
    }

    public function testRespondJsonReturnsThreadedConversationsWhenRequested(): void
    {
        $conversation = $this->createFeedbackConversation();
        $response = PrCommentsResponse::success([], [], [], 42, [$conversation], true);

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json, true);

        $this->assertNotNull($result);
        $payload = $result->toPayload();
        $this->assertAgentJsonSuccess($payload, ['mode' => 'threaded', 'pullNumber' => 42]);
        $this->assertSame('thread-1', $payload['data']['conversations'][0]['ids']['threadId']);
        $this->assertSame('alice', $payload['data']['conversations'][0]['comments'][0]['author']);
    }

    public function testRespondRendersThreadedConversationSections(): void
    {
        $conversation = $this->createFeedbackConversation();
        $truncated = new PullRequestFeedbackConversation(
            new PullRequestFeedbackIds('github', 'pr_comment'),
            'pr_comment',
            new PullRequestFeedbackState(resolved: true, outdated: true),
            [],
            new PullRequestFeedbackActions(),
            truncated: true,
        );
        $response = PrCommentsResponse::success([], [], [], 42, [$conversation, $truncated], true);

        $sections = [];
        $texts = [];
        $this->record($this->io, 'section', $sections);
        $this->record($this->io, 'text', $texts);
        $this->io->expects($this->atLeastOnce())->method('listing');

        $this->responder->respond($this->io, $response, OutputFormat::Cli, true);

        $this->assertContains('pr.comments.section {"number":42}', $sections);
        $this->assertContains('pr.comments.threaded_conversations', $sections);
        $this->assertRecordedStartsWith($sections, 'pr.comments.threaded_type_review_thread');
        $this->assertTrue(array_any($sections, static fn (string $call): bool => str_contains($call, 'pr.comments.threaded_state_unresolved')));
        $truncatedHeader = array_values(array_filter(
            $sections,
            static fn (string $call): bool => str_starts_with($call, 'pr.comments.threaded_type_pr_comment'),
        ));
        $this->assertNotEmpty($truncatedHeader);
        $this->assertStringContainsString('pr.comments.threaded_state_resolved', $truncatedHeader[0]);
        $this->assertStringContainsString('pr.comments.threaded_state_outdated', $truncatedHeader[0]);
        $this->assertStringContainsString('pr.comments.threaded_state_truncated', $truncatedHeader[0]);
        $this->assertRecordedStartsWith($texts, 'pr.comments.threaded_comment_meta');
    }

    public function testRespondRendersNoteForEmptyThreadedConversations(): void
    {
        $response = PrCommentsResponse::success([], [], [], 42, [], true);

        $sections = [];
        $this->record($this->io, 'section', $sections);
        $this->io->expects($this->once())->method('note')->with('pr.comments.no_comments');

        $this->responder->respond($this->io, $response, OutputFormat::Cli, true);

        $this->assertSame([
            'pr.comments.section {"number":42}',
            'pr.comments.threaded_conversations',
        ], $sections);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = PrCommentsResponse::error('API error');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertAgentJsonErrorString($result->toPayload(), 'API error');
    }

    public function testRespondCliRendersErrorMessageRef(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(\App\Service\Logger::VERBOSITY_NORMAL, 'pr.comments.error_fetch {"error":"boom"}');
        $responder = new PrCommentsResponder(
            new ResponderHelper($this->translationService),
            new CommentBodyParser(),
            $logger
        );

        $result = $responder->respond(
            $this->io,
            PrCommentsResponse::error(MessageRef::key('pr.comments.error_fetch', ['error' => 'boom']))
        );

        $this->assertNull($result);
    }

    private function createFeedbackConversation(): PullRequestFeedbackConversation
    {
        $ids = new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread-1', target: 'github:review_thread:thread-1');
        $state = new PullRequestFeedbackState(resolved: false, resolvable: true, outdated: false);
        $location = new PullRequestFeedbackLocation('src/File.php', 12);
        $actions = new PullRequestFeedbackActions(canReply: true, canResolve: true);
        $comment = new PullRequestFeedbackComment(
            new PullRequestFeedbackIds('github', 'review_comment', id: '1', threadId: 'thread-1'),
            'alice',
            new \DateTimeImmutable('2025-01-15T10:00:00Z'),
            'Thread body',
            $state,
            $location,
            $actions,
        );

        return new PullRequestFeedbackConversation($ids, 'review_thread', $state, [$comment], $actions, location: $location);
    }
}
