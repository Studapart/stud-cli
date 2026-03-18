<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\PullRequestComment;
use App\Enum\OutputFormat;
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

    public function testRespondShowsNoteWhenNoIssueCommentsAndNoReviewComments(): void
    {
        $response = PrCommentsResponse::success([], [], [], 42);

        $this->io->expects($this->exactly(4))->method('section')->with($this->anything());
        $this->io->expects($this->exactly(3))->method('note')->with($this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondRendersSectionAndTextForIssueComments(): void
    {
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), 'Body text', null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $this->io->expects($this->exactly(5))->method('section')->with($this->anything());
        $this->io->expects($this->exactly(2))->method('note')->with($this->anything());
        $this->io->expects($this->once())->method('text')->with($this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondRendersSectionAndTextForReviewComments(): void
    {
        $comment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Review body', 'src/File.php', 10);
        $response = PrCommentsResponse::success([], [$comment], [], 42);

        $this->io->expects($this->exactly(5))->method('section')->with($this->anything());
        $this->io->expects($this->exactly(2))->method('note')->with($this->anything());
        $this->io->expects($this->once())->method('text')->with($this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondRendersSectionsAndTextWhenBothCommentTypesPresent(): void
    {
        $issueComment = new PullRequestComment('alice', new \DateTimeImmutable(), 'Issue', null, null);
        $reviewComment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Review', 'file.php', 5);
        $response = PrCommentsResponse::success([$issueComment], [$reviewComment], [], 42);

        $this->io->expects($this->exactly(6))->method('section')->with($this->anything());
        $this->io->expects($this->once())->method('note')->with($this->anything());
        $this->io->expects($this->exactly(2))->method('text')->with($this->anything());

        $this->responder->respond($this->io, $response);
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

        $io->expects($this->exactly(6))->method('section')->with($this->anything());
        $io->expects($this->once())->method('note')->with($this->anything());
        $io->expects($this->exactly(2))->method('text')->with($this->anything());

        $responder->respond($io, $response);
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

        $io->expects($this->atLeastOnce())->method('section')->with($this->anything());
        $io->expects($this->once())->method('text')->with($this->anything());
        $io->expects($this->once())->method('listing')->with($this->anything());

        $responder->respond($io, $response);
    }

    public function testRespondRendersTableBlockWhenCommentBodyContainsMarkdownTable(): void
    {
        $body = "Some intro.\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n\nAfter table.";
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $this->io->expects($this->exactly(5))->method('section')->with($this->anything());
        $this->io->expects($this->exactly(2))->method('text')->with($this->anything());
        $this->io->expects($this->once())->method('table')->with($this->anything(), $this->anything());
        $this->io->expects($this->exactly(2))->method('note')->with($this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondRendersListingWhenCommentBodyContainsMarkdownList(): void
    {
        $body = "Items:\n\n- one\n- two\n- three";
        $comment = new PullRequestComment('bob', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $this->io->expects($this->exactly(5))->method('section')->with($this->anything());
        $this->io->expects($this->once())->method('text')->with($this->anything());
        $this->io->expects($this->once())->method('listing')->with($this->anything());
        $this->io->expects($this->exactly(2))->method('note')->with($this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondStripsBackticksFromCommentBodyForTerminalSafety(): void
    {
        $body = 'Run `ls -la` or use ```bash echo 1 ```';
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $this->io->expects($this->exactly(5))->method('section')->with($this->anything());
        $this->io->expects($this->exactly(2))->method('note')->with($this->anything());
        $this->io->expects($this->once())->method('text')->with($this->callback(function (string $text): bool {
            return ! str_contains($text, '`');
        }));

        $this->responder->respond($this->io, $response);
    }

    public function testRespondRendersReviewCommentWithPathButNullLine(): void
    {
        $comment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Comment', 'src/File.php', null);
        $response = PrCommentsResponse::success([], [$comment], [], 42);

        $this->io->expects($this->exactly(5))->method('section')->with($this->anything());
        $this->io->expects($this->exactly(2))->method('note')->with($this->anything());
        $this->io->expects($this->once())->method('text')->with($this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRespondRendersReviewsSectionWithReviewBodies(): void
    {
        $reviewComment = new PullRequestComment('reviewer', new \DateTimeImmutable(), 'Please fix the nits.', null, null);
        $response = PrCommentsResponse::success([], [], [$reviewComment], 42);

        $this->io->expects($this->exactly(5))->method('section')->with($this->anything());
        $this->io->expects($this->exactly(2))->method('note')->with($this->anything());
        $this->io->expects($this->once())->method('text')->with($this->anything());

        $this->responder->respond($this->io, $response);
    }

    public function testRenderTextSegmentWithEmptyContentDoesNotCallText(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->expects($this->never())->method('text');
        $responder = new PrCommentsResponder(new ResponderHelper($this->translationService), new CommentBodyParser(), $logger);

        $this->callPrivateMethod($responder, 'renderTextSegment', [['content' => '']]);
    }

    public function testRenderListSegmentWithEmptyItemsDoesNotCallListing(): void
    {
        $logger = $this->createMock(\App\Service\Logger::class);
        $logger->expects($this->never())->method('listing');
        $responder = new PrCommentsResponder(new ResponderHelper($this->translationService), new CommentBodyParser(), $logger);

        $this->callPrivateMethod($responder, 'renderListSegment', [['items' => []]]);
    }

    public function testRespondJsonReturnsSerializedComments(): void
    {
        $comment = new PullRequestComment('author', new \DateTimeImmutable(), 'body text');
        $response = PrCommentsResponse::success([$comment], [], [], 42);

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame(42, $result->data['pullNumber']);
        $this->assertCount(1, $result->data['issueComments']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = PrCommentsResponse::error('API error');

        $result = $this->responder->respond($this->io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertSame('API error', $result->error);
    }
}
