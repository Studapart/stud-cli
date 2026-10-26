<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\ResponseMessage;
use App\Handler\CommitHandler;
use App\Handler\FlattenHandler;
use App\Handler\PleaseHandler;
use App\Handler\PushHandler;
use App\Response\CommandResponse;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;
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
        ?PromptInterface $prompt = null,
        ?FlattenHandler $flattenHandler = null,
    ): PushHandler {
        return new PushHandler(
            $commitHandler,
            $gitRepository,
            $pleaseHandler,
            $this->translationService,
            $prompt ?? $this->createMock(PromptInterface::class),
            $flattenHandler ?? new FlattenHandler($gitRepository, 'origin/develop', $this->translationService),
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

        $response = $handler->handle($this->io(), false, null, false, false, false, false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->getErrorMessage(), 'push.commit_failed');
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

        $response = $handler->handle($this->io(), false, null, false, false, false, false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('push.commit_created', $response->data['commit']['message']);
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

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

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
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(0);

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

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

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
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(0);

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
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(0);

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

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('confirm')->willReturn(false);

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $prompt);

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
        $pleaseHandler->expects($this->once())->method('handle')->with(false)->willReturn(0);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('confirm')->willReturn(true);

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $prompt);

        $response = $handler->handle($this->io(), false, null, false, false, false, false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertMessageRef($response->message, 'push.please_completed');
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
        $pleaseHandler->expects($this->once())->method('handle')->willReturn(1);

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $response = $handler->handle($this->io(), false, null, false, true, false, false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->getErrorMessage(), 'push.please_failed');
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
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(0);

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertTrue($handler->handle(false, null, false, true, false, true, true)->isSuccess());
    }

    public function testFlattenAfterCreatedCommitForcePushesWithoutPromptInAgentMode(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('created', ['commitMessage' => 'feat: add [SCI-216]']));
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getPorcelainStatus')->willReturn('');
        $gitRepository->method('getMergeBase')->willReturn('base');
        $gitRepository->method('hasFixupCommits')->with('base')->willReturn(true);
        $gitRepository->expects($this->once())->method('rebaseAutosquash')->with('base');
        $gitRepository->expects($this->never())->method('pushHeadToOrigin');
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(CommandResponse::success('pushed'));
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('confirm');
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $prompt);

        $response = $handler->handle(false, null, true, true, false, true, true, true);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->data['published']);
        $this->assertTrue($response->data['rewritten']);
        $this->assertSame('feat: add [SCI-216]', $response->data['commit']['commitMessage']);
    }

    public function testFlattenPublishDeclineDoesNotPush(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('created', ['commitMessage' => 'feat: add [SCI-216]']));
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getPorcelainStatus')->willReturn('');
        $gitRepository->method('getMergeBase')->willReturn('base');
        $gitRepository->method('hasFixupCommits')->willReturn(true);
        $gitRepository->expects($this->once())->method('rebaseAutosquash');
        $gitRepository->expects($this->never())->method('pushHeadToOrigin');
        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('confirm')->willReturn(false);
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $prompt);

        $response = $handler->handle($this->io(), false, null, true, false, false, false, true, true);

        $this->assertFalse($response->isSuccess());
        $this->assertTrue($response->data['rewritten']);
        $this->assertArrayNotHasKey('published', $response->data);
        $this->assertSame('feat: add [SCI-216]', $response->data['commit']['commitMessage']);
    }

    public function testFlattenWithNoFixupsUsesNormalPush(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('created', ['fixupSha' => 'abc']));
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getPorcelainStatus')->willReturn('');
        $gitRepository->method('getMergeBase')->willReturn('base');
        $gitRepository->method('hasFixupCommits')->willReturn(false);
        $gitRepository->expects($this->never())->method('rebaseAutosquash');
        $gitRepository->expects($this->once())->method('pushHeadToOrigin')->willReturn($process);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $response = $handler->handle(false, null, true, true, false, true, true, true);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->data['published']);
        $this->assertFalse($response->data['rewritten']);
        $this->assertSame('abc', $response->data['commit']['fixupSha']);
    }

    public function testDirtyTreeSkipsFlattenAndPushes(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('created', ['commitMessage' => 'feat: add [SCI-216]']));
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getPorcelainStatus')->willReturn(' M left.txt');
        $gitRepository->expects($this->never())->method('rebaseAutosquash');
        $gitRepository->expects($this->once())->method('pushHeadToOrigin')->willReturn($process);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $handler = $this->createHandler($commitHandler, $gitRepository, $this->createMock(PleaseHandler::class));

        $response = $handler->handle(false, null, true, true, false, true, true, true);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->data['rewritten']);
        $this->assertTrue($response->data['published']);
        $this->assertNotEmpty($response->getNotices());
    }

    public function testFlattenRebaseFailureDoesNotPublish(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('created', ['commitMessage' => 'feat: add [SCI-216]']));
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getPorcelainStatus')->willReturn('');
        $gitRepository->method('getMergeBase')->willReturn('base');
        $gitRepository->method('hasFixupCommits')->willReturn(true);
        $gitRepository->method('rebaseAutosquash')->willThrowException(new \RuntimeException('Rebase failed'));
        $gitRepository->expects($this->never())->method('pushHeadToOrigin');
        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $response = $handler->handle(false, null, true, true, false, true, true, true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('feat: add [SCI-216]', $response->data['commitMessage']);
        $this->assertArrayNotHasKey('published', $response->data);
    }

    public function testFlattenConfirmedForcePushPublishesRewrittenHistory(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('created', ['commitMessage' => 'feat: add [SCI-216]']));
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getPorcelainStatus')->willReturn('');
        $gitRepository->method('getMergeBase')->willReturn('base');
        $gitRepository->method('hasFixupCommits')->willReturn(true);
        $gitRepository->expects($this->once())->method('rebaseAutosquash');
        $gitRepository->expects($this->never())->method('pushHeadToOrigin');
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->with(false)->willReturn(CommandResponse::success('pushed'));
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('confirm')->willReturn(true);
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $prompt);

        $response = $handler->handle(false, null, true, false, false, false, true, true);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->data['published']);
        $this->assertTrue($response->data['rewritten']);
    }

    public function testFlattenForcePushFailureKeepsCommitAndRewrittenFlag(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('created', ['commitMessage' => 'feat: add [SCI-216]']));
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getPorcelainStatus')->willReturn('');
        $gitRepository->method('getMergeBase')->willReturn('base');
        $gitRepository->method('hasFixupCommits')->willReturn(true);
        $gitRepository->method('rebaseAutosquash');
        $gitRepository->expects($this->never())->method('pushHeadToOrigin');
        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->method('handle')->willReturn(CommandResponse::error('force failed'));
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $response = $handler->handle(false, null, true, true, false, true, true, true);

        $this->assertFalse($response->isSuccess());
        $this->assertTrue($response->data['rewritten']);
        $this->assertSame('feat: add [SCI-216]', $response->data['commit']['commitMessage']);
        $this->assertArrayNotHasKey('published', $response->data);
    }

    public function testSkippedFlattenKeepsRewrittenWhenPushFails(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('created', ['commitMessage' => 'feat: add [SCI-216]']));
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getPorcelainStatus')->willReturn(' M left.txt');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $response = $handler->handle(false, null, true, true, false, true, false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->data['rewritten']);
        $this->assertArrayNotHasKey('published', $response->data);
        $this->assertSame('flatten.notice_skipped', (string) $response->getNotices()[0]->message);
    }

    public function testSkippedFlattenKeepsNoticeWhenPleaseFallbackSucceeds(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(CommandResponse::success('created', ['commitMessage' => 'feat: add [SCI-216]']));
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $gitRepository = $this->createMock(GitRepository::class);
        $this->expectCommitPath($gitRepository);
        $gitRepository->method('getPorcelainStatus')->willReturn(' M left.txt');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->with(true)->willReturn(
            CommandResponse::success('force pushed', [], [ResponseMessage::warning('please done')]),
        );
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $response = $handler->handle(false, null, true, true, false, true, true, true);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->data['rewritten']);
        $this->assertTrue($response->data['published']);
        $this->assertSame('feat: add [SCI-216]', $response->data['commit']['commitMessage']);
        $this->assertSame('flatten.notice_skipped', (string) $response->getNotices()[0]->message);
        $this->assertSame('please done', (string) $response->getWarnings()[0]->message);
    }
}
