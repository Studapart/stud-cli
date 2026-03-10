<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\DTO\PullRequestComment;
use App\Responder\PrCommentsResponder;
use App\Response\PrCommentsResponse;
use App\Service\ColorHelper;
use App\Service\CommentBodyParser;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class PrCommentsResponderTest extends CommandTestCase
{
    private PrCommentsResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responder = new PrCommentsResponder($this->translationService, new CommentBodyParser(), null);
    }

    public function testRespondShowsNoteWhenNoIssueCommentsAndNoReviewComments(): void
    {
        $response = PrCommentsResponse::success([], [], [], 42);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(4))->method('section')->with($this->anything());
        $io->expects($this->exactly(3))->method('note')->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersSectionAndTextForIssueComments(): void
    {
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), 'Body text', null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(5))->method('section')->with($this->anything());
        $io->expects($this->exactly(2))->method('note')->with($this->anything());
        $io->expects($this->once())->method('text')->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersSectionAndTextForReviewComments(): void
    {
        $comment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Review body', 'src/File.php', 10);
        $response = PrCommentsResponse::success([], [$comment], [], 42);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(5))->method('section')->with($this->anything());
        $io->expects($this->exactly(2))->method('note')->with($this->anything());
        $io->expects($this->once())->method('text')->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersSectionsAndTextWhenBothCommentTypesPresent(): void
    {
        $issueComment = new PullRequestComment('alice', new \DateTimeImmutable(), 'Issue', null, null);
        $reviewComment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Review', 'file.php', 5);
        $response = PrCommentsResponse::success([$issueComment], [$reviewComment], [], 42);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(6))->method('section')->with($this->anything());
        $io->expects($this->once())->method('note')->with($this->anything());
        $io->expects($this->exactly(2))->method('text')->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondWithColorHelperFormatsSectionTitles(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $responder = new PrCommentsResponder($this->translationService, new CommentBodyParser(), $colorHelper);
        $issueComment = new PullRequestComment('alice', new \DateTimeImmutable(), 'Body', null, null);
        $reviewComment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Review', 'file.php', 5);
        $response = PrCommentsResponse::success([$issueComment], [$reviewComment], [], 42);
        $io = $this->createMock(SymfonyStyle::class);

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
        $responder = new PrCommentsResponder($this->translationService, new CommentBodyParser(), $colorHelper);
        $body = "Checklist:\n\n- one\n- two";
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);
        $io = $this->createMock(SymfonyStyle::class);

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
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(5))->method('section')->with($this->anything());
        $io->expects($this->exactly(2))->method('text')->with($this->anything());
        $io->expects($this->once())->method('table')->with($this->anything(), $this->anything());
        $io->expects($this->exactly(2))->method('note')->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersListingWhenCommentBodyContainsMarkdownList(): void
    {
        $body = "Items:\n\n- one\n- two\n- three";
        $comment = new PullRequestComment('bob', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(5))->method('section')->with($this->anything());
        $io->expects($this->once())->method('text')->with($this->anything());
        $io->expects($this->once())->method('listing')->with($this->anything());
        $io->expects($this->exactly(2))->method('note')->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondStripsBackticksFromCommentBodyForTerminalSafety(): void
    {
        $body = 'Run `ls -la` or use ```bash echo 1 ```';
        $comment = new PullRequestComment('alice', new \DateTimeImmutable(), $body, null, null);
        $response = PrCommentsResponse::success([$comment], [], [], 42);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(5))->method('section')->with($this->anything());
        $io->expects($this->exactly(2))->method('note')->with($this->anything());
        $io->expects($this->once())->method('text')->with($this->callback(function (string $text): bool {
            return ! str_contains($text, '`');
        }));

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersReviewCommentWithPathButNullLine(): void
    {
        $comment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Comment', 'src/File.php', null);
        $response = PrCommentsResponse::success([], [$comment], [], 42);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(5))->method('section')->with($this->anything());
        $io->expects($this->exactly(2))->method('note')->with($this->anything());
        $io->expects($this->once())->method('text')->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRespondRendersReviewsSectionWithReviewBodies(): void
    {
        $reviewComment = new PullRequestComment('reviewer', new \DateTimeImmutable(), 'Please fix the nits.', null, null);
        $response = PrCommentsResponse::success([], [], [$reviewComment], 42);
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->exactly(5))->method('section')->with($this->anything());
        $io->expects($this->exactly(2))->method('note')->with($this->anything());
        $io->expects($this->once())->method('text')->with($this->anything());

        $this->responder->respond($io, $response);
    }

    public function testRenderTextSegmentWithEmptyContentDoesNotCallText(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->never())->method('text');

        $this->callPrivateMethod($this->responder, 'renderTextSegment', [$io, ['content' => '']]);
    }

    public function testRenderListSegmentWithEmptyItemsDoesNotCallListing(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->never())->method('listing');

        $this->callPrivateMethod($this->responder, 'renderListSegment', [$io, ['items' => []]]);
    }
}
