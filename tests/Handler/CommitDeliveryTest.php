<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\CommitDeliveryInput;
use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Handler\CommitDelivery;
use App\Handler\CommitHandler;
use App\Handler\FlattenHandler;
use App\Response\CommandResponse;
use PHPUnit\Framework\TestCase;

class CommitDeliveryTest extends TestCase
{
    public function testFlattenFalseDoesNotFlatten(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->expects($this->once())->method('handle')->with(false, null, true, true)->willReturn(
            CommandResponse::success(MessageRef::key('commit.success')),
        );
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->never())->method('handle');

        $response = (new CommitDelivery($commitHandler, $flattenHandler))->handle(new CommitDeliveryInput(stageAll: true, quiet: true));

        $this->assertTrue($response->isSuccess());
    }

    public function testCommitFailureSkipsFlatten(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::error(MessageRef::key('commit.no_staged_changes')));
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->never())->method('handle');

        $response = (new CommitDelivery($commitHandler, $flattenHandler))->handle(new CommitDeliveryInput(flatten: true));

        $this->assertFalse($response->isSuccess());
    }

    public function testFlattenFailureAfterCommitDoesNotReportSuccess(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success(
            MessageRef::key('commit.success'),
            messages: [ResponseMessage::notice(MessageRef::key('commit.note_clean_working_tree'))],
        ));
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->once())->method('handle')->willReturn(
            CommandResponse::error(MessageRef::key('flatten.error_dirty_working')),
        );

        $response = (new CommitDelivery($commitHandler, $flattenHandler))->handle(
            new CommitDeliveryInput(quiet: true, flatten: true),
        );

        $this->assertFalse($response->isSuccess());
        $error = $response->getErrorMessage();
        $this->assertInstanceOf(MessageRef::class, $error);
        $this->assertSame('flatten.error_dirty_working', $error->key);
        $this->assertNotEmpty($response->getNotices());
    }

    public function testFlattenSuccessKeepsRewriteWarning(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->method('handle')->willReturn(CommandResponse::success(messages: [
            ResponseMessage::warning(MessageRef::key('flatten.warning_rewrite')),
        ]));

        $response = (new CommitDelivery($commitHandler, $flattenHandler))->handle(new CommitDeliveryInput(flatten: true));

        $this->assertTrue($response->isSuccess());
        $this->assertNotEmpty($response->getWarnings());
    }
}
