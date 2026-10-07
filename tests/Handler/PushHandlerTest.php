<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Handler\CommitHandler;
use App\Handler\FlattenHandler;
use App\Handler\PleaseHandler;
use App\Handler\PushHandler;
use App\Response\CommandResponse;
use App\Service\GitRepository;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class PushHandlerTest extends CommandTestCase
{
    private function createHandler(
        CommitHandler $commitHandler,
        GitRepository $gitRepository,
        PleaseHandler $pleaseHandler,
        ?Logger $logger = null,
        ?FlattenHandler $flattenHandler = null,
    ): PushHandler {
        $logger ??= $this->createMock(Logger::class);
        if ($flattenHandler === null) {
            $flattenHandler = $this->createMock(FlattenHandler::class);
            $flattenHandler->expects($this->never())->method('handle');
        }

        return new PushHandler(
            $commitHandler,
            $gitRepository,
            $pleaseHandler,
            $flattenHandler,
            $this->translationService,
            $logger,
        );
    }

    private function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    /**
     * Existing tests expect a commit phase; stub staged changes so soft-skip does not apply.
     */
    private function expectCommitPath(GitRepository $gitRepository): void
    {
        $gitRepository->method('hasStagedChanges')->willReturn(true);
    }

    public function testCommitFailureSkipsPush(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->expects($this->once())->method('handle')->willReturn(2);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->expects($this->never())->method('pushHeadToOrigin');

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertFalse($handler->handle($this->io(), false, null, false, false, false, false, true)->isSuccess());
    }

    public function testPushSuccess(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->expects($this->once())->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertTrue($handler->handle($this->io(), false, null, false, false, false, false, true)->isSuccess());
    }

    public function testPushSkipsCommitWhenNothingStagedAndDirty(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->expects($this->never())->method('handle');

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('hasStagedChanges')->willReturn(false);
        $gitRepository->method('getPorcelainStatus')->willReturn(' M a.txt');
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->expects($this->once())->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $response = $handler->handle(false, null, false, true, false, true, true);
        $this->assertTrue($response->isSuccess());
        $this->assertNotEmpty($response->getMessages());
    }

    public function testPushSkipsCommitWhenNothingStagedAndClean(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->expects($this->never())->method('handle');

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('hasStagedChanges')->willReturn(false);
        $gitRepository->method('getPorcelainStatus')->willReturn('');
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->expects($this->once())->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $response = $handler->handle(false, null, false, true, false, true, true);
        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->getMessages());
    }

    public function testPushStillCommitsWhenStageAllAndNothingStagedYet(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->expects($this->once())->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('hasStagedChanges')->willReturn(false);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->expects($this->once())->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertTrue($handler->handle(false, null, true, true, false, true, true)->isSuccess());
    }

    public function testPushFailsWithNoPleaseReturnsOne(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');

        $logger = $this->createMock(Logger::class);
        $logger->method('addError');

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $logger);

        $this->assertFalse($handler->handle($this->io(), false, null, false, false, true, false, true)->isSuccess());
    }

    public function testPushFailsQuietRunsPlease(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(CommandResponse::success());

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertTrue($handler->handle($this->io(), false, null, false, true, false, false, true)->isSuccess());
    }

    public function testPushFailsAgentWithPleaseFallbackFalse(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');

        $logger = $this->createMock(Logger::class);
        $logger->method('addError');

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $logger);

        $this->assertFalse($handler->handle($this->io(), false, null, false, true, false, true, false)->isSuccess());
    }

    public function testPushFailsAgentWithPleaseFallbackTrueRunsPlease(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(CommandResponse::success());

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertTrue($handler->handle($this->io(), false, null, false, true, false, true, true)->isSuccess());
    }

    /**
     * In agent mode, CLI-only noPlease is not passed through; pleaseFallback is the sole control.
     */
    public function testPushFailsAgentIgnoresNoPleaseWhenPleaseFallbackTrue(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(CommandResponse::success());

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertTrue($handler->handle($this->io(), false, null, false, true, true, true, true)->isSuccess());
    }

    public function testPushFailsInteractiveUserDeclinesPlease(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('confirm')->willReturn(false);
        $logger->method('addError');

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $logger);

        $this->assertFalse($handler->handle($this->io(), false, null, false, false, false, false, true)->isSuccess());
    }

    public function testPushFailsInteractiveUserAcceptsPlease(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->with(false)->willReturn(CommandResponse::success());

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('confirm')->willReturn(true);

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $logger);

        $this->assertTrue($handler->handle($this->io(), false, null, false, false, false, false, true)->isSuccess());
    }

    public function testPleaseFailureExitCodePropagates(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->willReturn(CommandResponse::error(MessageRef::key('push.error_push')));

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertFalse($handler->handle($this->io(), false, null, false, true, false, false, true)->isSuccess());
    }

    public function testNewSignatureUsesCommandResponses(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('Commit created'));

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->expects($this->once())->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $response = $handler->handle(false, null, false, true, false, false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('feat/foo', $response->data['branch'] ?? null);
    }

    public function testPleaseCommandResponsePropagates(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('Commit created'));

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->willReturn(CommandResponse::success('Force push completed'));

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertTrue($handler->handle(false, null, false, true, false, true, true)->isSuccess());
    }

    public function testDirtyTreeSoftSkipStillRunsPleaseFallback(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->expects($this->never())->method('handle');

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('hasStagedChanges')->willReturn(false);
        $gitRepository->method('getPorcelainStatus')->willReturn(' M a.txt');
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(CommandResponse::success());

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertTrue($handler->handle(false, null, false, true, false, true, true)->isSuccess());
    }

    public function testFlattenFailureSkipsPush(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success(MessageRef::key('push.success')));

        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->expects($this->never())->method('pushHeadToOrigin');

        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->once())->method('handle')->willReturn(
            CommandResponse::error(MessageRef::key('flatten.error_rebase', ['error' => 'conflict'])),
        );

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, flattenHandler: $flattenHandler);

        $response = $handler->handle(false, null, true, true, false, true, true, true, true);

        $this->assertFalse($response->isSuccess());
        $error = $response->getErrorMessage();
        $this->assertInstanceOf(MessageRef::class, $error);
        $this->assertSame('flatten.error_rebase', $error->key);
    }

    public function testFlattenRewritesThenPleaseFallbackAfterRejectedPush(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $flattened = false;
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->once())->method('handle')->willReturnCallback(
            function () use (&$flattened): CommandResponse {
                $flattened = true;

                return CommandResponse::success(messages: [
                    ResponseMessage::warning(MessageRef::key('flatten.warning_rewrite')),
                ]);
            },
        );

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getCurrentBranchName')->willReturn('chore/SCI-213');
        $gitRepository->expects($this->once())->method('pushHeadToOrigin')->willReturnCallback(
            function () use (&$flattened, $process): Process {
                $this->assertTrue($flattened);

                return $process;
            },
        );

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(
            CommandResponse::success(MessageRef::key('push.success')),
        );
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, flattenHandler: $flattenHandler);

        $response = $handler->handle(false, null, true, true, false, true, true, true, true);

        $this->assertTrue($response->isSuccess());
        $this->assertNotEmpty($response->getWarnings());
    }
}
