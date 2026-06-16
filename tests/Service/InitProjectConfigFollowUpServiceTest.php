<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\WorkflowOutputEntry;
use App\Handler\ConfigProjectInitHandler;
use App\Responder\ConfigProjectInitResponder;
use App\Response\ConfigProjectInitResponse;
use App\Response\WorkflowResponse;
use App\Service\GitRepository;
use App\Service\InitProjectConfigFollowUpService;
use App\Service\ProjectStudConfigAdequacyChecker;
use App\Service\Prompt\PromptInterface;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class InitProjectConfigFollowUpServiceTest extends TestCase
{
    public function testNotInGitRepositoryShowsHint(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willThrowException(new \RuntimeException('not git'));

        $service = $this->createService($git, $this->createMock(ConfigProjectInitHandler::class));

        $io = $this->createIo(true);
        $result = $service->augmentAfterGlobalSave(WorkflowResponse::fromExitCode(0), true, $io);

        $this->assertTrue($this->hasEntryType($result, 'note'));
    }

    public function testAdequateConfigDoesNothing(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willReturn('/repo/.git/stud.config');
        $git->method('readProjectConfig')->willReturn(['baseBranch' => 'develop']);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('confirm');

        $service = $this->createService($git, $this->createMock(ConfigProjectInitHandler::class), $prompt);

        $io = $this->createIo(true);
        $result = $service->augmentAfterGlobalSave(WorkflowResponse::fromExitCode(0), true, $io);

        $this->assertSame([], $result->entries);
    }

    public function testInadequateNonInteractiveShowsHint(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willReturn('/repo/.git/stud.config');
        $git->method('readProjectConfig')->willReturn([]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('confirm');

        $service = $this->createService($git, $this->createMock(ConfigProjectInitHandler::class), $prompt);

        $io = $this->createIo(false);
        $result = $service->augmentAfterGlobalSave(WorkflowResponse::fromExitCode(0), false, $io);

        $this->assertTrue($this->hasEntryType($result, 'note'));
    }

    public function testInadequateInteractiveDeclineShowsHint(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willReturn('/repo/.git/stud.config');
        $git->method('readProjectConfig')->willReturn([]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('confirm')->willReturn(false);

        $handler = $this->createMock(ConfigProjectInitHandler::class);
        $handler->expects($this->never())->method('handle');

        $service = $this->createService($git, $handler, $prompt);

        $io = $this->createIo(true);
        $result = $service->augmentAfterGlobalSave(WorkflowResponse::fromExitCode(0), true, $io);

        $this->assertTrue($this->hasEntryType($result, 'note'));
    }

    public function testInadequateInteractiveAcceptRunsProjectInit(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willReturn('/repo/.git/stud.config');
        $git->method('readProjectConfig')->willReturn([]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('confirm')->willReturn(true);

        $response = ConfigProjectInitResponse::success(false, []);

        $handler = $this->createMock(ConfigProjectInitHandler::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with([], [], false, true, false, $this->isInstanceOf(\App\Contract\WorkflowEntryRecorder::class))
            ->willReturn($response);

        $responder = $this->createMock(ConfigProjectInitResponder::class);
        $responder->expects($this->once())
            ->method('respond')
            ->with($this->anything(), $response, \App\Enum\OutputFormat::Cli);

        $service = $this->createService($git, $handler, $prompt, $responder);

        $io = $this->createIo(true);
        $service->augmentAfterGlobalSave(WorkflowResponse::fromExitCode(0), true, $io);
    }

    private function createIo(bool $interactive): SymfonyStyle
    {
        $input = new ArrayInput([]);
        $input->setInteractive($interactive);

        return new SymfonyStyle($input, new BufferedOutput());
    }

    private function hasEntryType(WorkflowResponse $response, string $type): bool
    {
        foreach ($response->entries as $entry) {
            if ($entry instanceof WorkflowOutputEntry && $entry->type === $type) {
                return true;
            }
        }

        return false;
    }

    private function createService(
        GitRepository $git,
        ConfigProjectInitHandler $handler,
        ?PromptInterface $prompt = null,
        ?ConfigProjectInitResponder $responder = null,
    ): InitProjectConfigFollowUpService {
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $translator = new TranslationService('en', $translationsPath);
        $prompt ??= $this->createMock(PromptInterface::class);
        $responder ??= $this->createMock(ConfigProjectInitResponder::class);

        return new InitProjectConfigFollowUpService(
            $git,
            new ProjectStudConfigAdequacyChecker(),
            $handler,
            $responder,
            $translator,
            $prompt,
        );
    }
}
