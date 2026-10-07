<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\DTO\SubmitOptions;
use App\Handler\FlattenHandler;
use App\Handler\SubmitDelivery;
use App\Handler\SubmitHandler;
use App\Response\CommandResponse;
use App\Response\WorkflowResponse;
use PHPUnit\Framework\TestCase;

class SubmitDeliveryTest extends TestCase
{
    public function testFlattenFalseWithoutPushPhaseOpensPullRequest(): void
    {
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->never())->method('handle');
        $submitHandler = $this->createMock(SubmitHandler::class);
        $submitHandler->expects($this->once())->method('handle')->willReturn(WorkflowResponse::fromExitCode(0, pullNumber: 12));

        $response = (new SubmitDelivery($flattenHandler, $submitHandler))->handle(false, null, new SubmitOptions());

        $this->assertInstanceOf(WorkflowResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame(12, $response->pullNumber);
    }

    public function testPushPhaseFailureDoesNotOpenPullRequest(): void
    {
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->never())->method('handle');
        $submitHandler = $this->createMock(SubmitHandler::class);
        $submitHandler->expects($this->never())->method('handle');
        $push = static fn (): CommandResponse => CommandResponse::error(MessageRef::key('flatten.error_rebase', ['error' => 'conflict']));

        $response = (new SubmitDelivery($flattenHandler, $submitHandler))->handle(true, $push, new SubmitOptions());

        $this->assertInstanceOf(CommandResponse::class, $response);
        $this->assertFalse($response->isSuccess());
    }

    public function testStandaloneFlattenFailureDoesNotOpenPullRequest(): void
    {
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->once())->method('handle')->willReturn(
            CommandResponse::error(MessageRef::key('flatten.error_dirty_working')),
        );
        $submitHandler = $this->createMock(SubmitHandler::class);
        $submitHandler->expects($this->never())->method('handle');

        $response = (new SubmitDelivery($flattenHandler, $submitHandler))->handle(true, null, new SubmitOptions());

        $this->assertInstanceOf(CommandResponse::class, $response);
        $this->assertFalse($response->isSuccess());
    }

    public function testSuccessfulFlattenKeepsRewriteWarningOnPullRequest(): void
    {
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->once())->method('handle')->willReturn(CommandResponse::success(messages: [
            ResponseMessage::warning(MessageRef::key('flatten.warning_rewrite')),
        ]));
        $submitHandler = $this->createMock(SubmitHandler::class);
        $submitHandler->expects($this->once())->method('handle')->willReturn(WorkflowResponse::fromExitCode(0, pullNumber: 4));

        $response = (new SubmitDelivery($flattenHandler, $submitHandler))->handle(true, null, new SubmitOptions());

        $this->assertInstanceOf(WorkflowResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertNotEmpty($response->getWarnings());
    }

    public function testPushPhaseSuccessWithoutFlattenStillOpensPullRequest(): void
    {
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->never())->method('handle');
        $submitHandler = $this->createMock(SubmitHandler::class);
        $submitHandler->expects($this->once())->method('handle')->willReturn(WorkflowResponse::fromExitCode(0, pullNumber: 3));
        $push = static fn (): CommandResponse => CommandResponse::success(MessageRef::key('push.success'));

        $response = (new SubmitDelivery($flattenHandler, $submitHandler))->handle(false, $push, new SubmitOptions());

        $this->assertInstanceOf(WorkflowResponse::class, $response);
        $this->assertSame(3, $response->pullNumber);
        $this->assertSame([], $response->getMessages());
    }

    public function testPushPhaseSuccessWithFlattenKeepsMessagesAndOpensPullRequest(): void
    {
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->never())->method('handle');
        $submitHandler = $this->createMock(SubmitHandler::class);
        $submitHandler->expects($this->once())->method('handle')->willReturn(WorkflowResponse::fromExitCode(0, pullNumber: 9));
        $push = static fn (): CommandResponse => CommandResponse::success(messages: [
            ResponseMessage::warning(MessageRef::key('flatten.warning_rewrite')),
        ]);

        $response = (new SubmitDelivery($flattenHandler, $submitHandler))->handle(true, $push, new SubmitOptions());

        $this->assertInstanceOf(WorkflowResponse::class, $response);
        $this->assertSame(9, $response->pullNumber);
        $this->assertNotEmpty($response->getWarnings());
    }
}
