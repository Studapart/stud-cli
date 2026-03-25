<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\OutputFormat;
use App\Handler\ConfigProjectInitHandler;
use App\Responder\ConfigProjectInitResponder;
use App\Response\ConfigProjectInitResponse;
use App\Service\GitRepository;
use App\Service\InitProjectConfigFollowUpService;
use App\Service\Logger;
use App\Service\ProjectStudConfigAdequacyChecker;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class InitProjectConfigFollowUpServiceTest extends TestCase
{
    public function testSkipsWhenAgentMode(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->expects($this->never())->method('getProjectConfigPath');

        $service = $this->createService($git, $this->createMock(ConfigProjectInitHandler::class));

        $io = $this->createIo(true);
        $service->runAfterGlobalSave($io, true, true);
    }

    public function testNotInGitRepositoryShowsHint(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willThrowException(new \RuntimeException('not git'));

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('note');

        $service = $this->createService($git, $this->createMock(ConfigProjectInitHandler::class), $logger);

        $io = $this->createIo(true);
        $service->runAfterGlobalSave($io, false, true);
    }

    public function testAdequateConfigDoesNothing(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willReturn('/repo/.git/stud.config');
        $git->method('readProjectConfig')->willReturn(['baseBranch' => 'develop']);

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('note');
        $logger->expects($this->never())->method('confirm');

        $service = $this->createService($git, $this->createMock(ConfigProjectInitHandler::class), $logger);

        $io = $this->createIo(true);
        $service->runAfterGlobalSave($io, false, true);
    }

    public function testInadequateNonInteractiveShowsHint(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willReturn('/repo/.git/stud.config');
        $git->method('readProjectConfig')->willReturn([]);

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->never())->method('confirm');
        $logger->expects($this->once())->method('note');

        $service = $this->createService($git, $this->createMock(ConfigProjectInitHandler::class), $logger);

        $io = $this->createIo(false);
        $service->runAfterGlobalSave($io, false, false);
    }

    public function testInadequateInteractiveDeclineShowsHint(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willReturn('/repo/.git/stud.config');
        $git->method('readProjectConfig')->willReturn([]);

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('confirm')->willReturn(false);
        $logger->expects($this->once())->method('note');

        $handler = $this->createMock(ConfigProjectInitHandler::class);
        $handler->expects($this->never())->method('handle');

        $service = $this->createService($git, $handler, $logger);

        $io = $this->createIo(true);
        $service->runAfterGlobalSave($io, false, true);
    }

    public function testInadequateInteractiveAcceptRunsProjectInit(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getProjectConfigPath')->willReturn('/repo/.git/stud.config');
        $git->method('readProjectConfig')->willReturn([]);

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('confirm')->willReturn(true);
        $logger->expects($this->never())->method('note');

        $response = ConfigProjectInitResponse::success(false, []);

        $handler = $this->createMock(ConfigProjectInitHandler::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with([], [], false, true, false)
            ->willReturn($response);

        $responder = $this->createMock(ConfigProjectInitResponder::class);
        $responder->expects($this->once())
            ->method('respond')
            ->with($this->anything(), $response, OutputFormat::Cli);

        $service = $this->createService($git, $handler, $logger, $responder);

        $io = $this->createIo(true);
        $service->runAfterGlobalSave($io, false, true);
    }

    private function createIo(bool $interactive): SymfonyStyle
    {
        $input = new ArrayInput([]);
        $input->setInteractive($interactive);

        return new SymfonyStyle($input, new BufferedOutput());
    }

    private function createService(
        GitRepository $git,
        ConfigProjectInitHandler $handler,
        ?Logger $logger = null,
        ?ConfigProjectInitResponder $responder = null,
    ): InitProjectConfigFollowUpService {
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $translator = new TranslationService('en', $translationsPath);
        $logger ??= $this->createMock(Logger::class);
        $responder ??= $this->createMock(ConfigProjectInitResponder::class);

        return new InitProjectConfigFollowUpService(
            $git,
            new ProjectStudConfigAdequacyChecker(),
            $handler,
            $responder,
            $translator,
            $logger,
        );
    }
}
