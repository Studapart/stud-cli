<?php

namespace App\Tests\Responder;

use App\Enum\OutputFormat;
use App\Responder\ItemUpdateResponder;
use App\Response\ItemUpdateResponse;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemUpdateResponderTest extends CommandTestCase
{
    public function testRespondCallsSuccessWithKey(): void
    {
        $response = ItemUpdateResponse::success('SCI-71');
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemUpdateResponder($this->translationService, $this->createLogger($io));

        $io->expects($this->once())
            ->method('success')
            ->with($this->callback(fn ($msg) => str_contains($msg, 'SCI-71')));

        $responder->respond($io, $response);
    }

    public function testRespondDoesNotCallSuccessWhenError(): void
    {
        $response = ItemUpdateResponse::error('Something failed');
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemUpdateResponder($this->translationService, $this->createLogger($io));

        $io->expects($this->never())->method('success');

        $responder->respond($io, $response);
    }

    public function testRespondShowsNoteWhenSkippedOptionalFields(): void
    {
        $response = ItemUpdateResponse::success('SCI-71', ['unknown_field']);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemUpdateResponder($this->translationService, $this->createLogger($io));

        $io->expects($this->once())->method('success');
        $io->expects($this->once())
            ->method('note')
            ->with($this->callback(fn (string $msg) => str_contains($msg, 'unknown_field')));

        $responder->respond($io, $response);
    }

    public function testRespondJsonReturnsUpdatedIssueData(): void
    {
        $response = ItemUpdateResponse::success('SCI-71');
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemUpdateResponder($this->translationService, $this->createLogger($io));

        $result = $responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertTrue($result->success);
        $this->assertSame('SCI-71', $result->data['key']);
    }

    public function testRespondJsonReturnsErrorOnFailure(): void
    {
        $response = ItemUpdateResponse::error('Update failed');
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemUpdateResponder($this->translationService, $this->createLogger($io));

        $result = $responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
    }

    public function testRespondJsonReturnsSkippedFields(): void
    {
        $response = ItemUpdateResponse::success('SCI-71', ['bad_field']);
        $io = $this->createMock(SymfonyStyle::class);
        $responder = new ItemUpdateResponder($this->translationService, $this->createLogger($io));

        $result = $responder->respond($io, $response, OutputFormat::Json);

        $this->assertNotNull($result);
        $this->assertSame(['bad_field'], $result->data['skippedOptionalFields']);
    }
}
