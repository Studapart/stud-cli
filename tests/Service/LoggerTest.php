<?php

namespace App\Tests\Service;

use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class LoggerTest extends CommandTestCase
{
    public function testErrorRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(true);

        $io->expects($this->once())
            ->method('error')
            ->with('Test error');

        $logger = new Logger($io, []);
        $logger->error(Logger::VERBOSITY_NORMAL, 'Test error');
    }

    public function testErrorSuppressedWhenQuiet(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(true);

        $io->expects($this->never())
            ->method('error');

        $logger = new Logger($io, []);
        $logger->error(Logger::VERBOSITY_NORMAL, 'Test error');
    }

    public function testWarningRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('warning')
            ->with('Test warning');

        $logger = new Logger($io, []);
        $logger->warning(Logger::VERBOSITY_NORMAL, 'Test warning');
    }

    public function testNoteRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('note')
            ->with('Test note');

        $logger = new Logger($io, []);
        $logger->note(Logger::VERBOSITY_NORMAL, 'Test note');
    }

    public function testSuccessRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('success')
            ->with('Test success');

        $logger = new Logger($io, []);
        $logger->success(Logger::VERBOSITY_NORMAL, 'Test success');
    }

    public function testTextRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('text')
            ->with('Test text');

        $logger = new Logger($io, []);
        $logger->text(Logger::VERBOSITY_NORMAL, 'Test text');
    }

    public function testWritelnRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(true);

        $io->expects($this->once())
            ->method('writeln')
            ->with('Test writeln');

        $logger = new Logger($io, []);
        $logger->writeln(Logger::VERBOSITY_VERBOSE, 'Test writeln');
    }

    public function testVerboseMessageNotShownAtNormalLevel(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->never())
            ->method('writeln');

        $logger = new Logger($io, []);
        $logger->writeln(Logger::VERBOSITY_VERBOSE, 'Test verbose');
    }

    public function testVeryVerboseMessageNotShownAtVerboseLevel(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(true);

        $io->expects($this->never())
            ->method('writeln');

        $logger = new Logger($io, []);
        $logger->writeln(Logger::VERBOSITY_VERY_VERBOSE, 'Test very verbose');
    }

    public function testDebugMessageShownAtDebugLevel(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(true);
        $io->method('isVeryVerbose')->willReturn(true);
        $io->method('isVerbose')->willReturn(true);

        $io->expects($this->once())
            ->method('writeln')
            ->with('Test debug');

        $logger = new Logger($io, []);
        $logger->writeln(Logger::VERBOSITY_DEBUG, 'Test debug');
    }

    public function testJiraWritelnAppliesJiraColor(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(true);

        $colors = ['jira_message' => 'bright-blue'];
        $io->expects($this->once())
            ->method('writeln')
            ->with('<fg=bright-blue>Fetching issue: TPW-35</>');

        $logger = new Logger($io, $colors);
        $logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, 'Fetching issue: TPW-35');
    }

    public function testJiraWritelnRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $colors = ['jira_message' => 'bright-blue'];
        $io->expects($this->never())
            ->method('writeln');

        $logger = new Logger($io, $colors);
        $logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, 'Fetching issue: TPW-35');
    }

    public function testGitWritelnAppliesGitColor(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(true);

        $colors = ['git_message' => 'yellow'];
        $io->expects($this->once())
            ->method('writeln')
            ->with('<fg=yellow>Branch created: feat/TPW-35</>');

        $logger = new Logger($io, $colors);
        $logger->gitWriteln(Logger::VERBOSITY_VERBOSE, 'Branch created: feat/TPW-35');
    }

    public function testGitWritelnRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $colors = ['git_message' => 'yellow'];
        $io->expects($this->never())
            ->method('writeln');

        $logger = new Logger($io, $colors);
        $logger->gitWriteln(Logger::VERBOSITY_VERBOSE, 'Branch created: feat/TPW-35');
    }

    public function testJiraTextAppliesJiraColor(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $colors = ['jira_message' => 'bright-blue'];
        $io->expects($this->once())
            ->method('text')
            ->with('<fg=bright-blue>Issue TPW-35 details</>');

        $logger = new Logger($io, $colors);
        $logger->jiraText(Logger::VERBOSITY_NORMAL, 'Issue TPW-35 details');
    }

    public function testJiraTextWithArrayAppliesJiraColor(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $colors = ['jira_message' => 'bright-blue'];
        $io->expects($this->once())
            ->method('text')
            ->with(['<fg=bright-blue>Line 1</>', '<fg=bright-blue>Line 2</>']);

        $logger = new Logger($io, $colors);
        $logger->jiraText(Logger::VERBOSITY_NORMAL, ['Line 1', 'Line 2']);
    }

    public function testGitTextAppliesGitColor(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $colors = ['git_message' => 'yellow'];
        $io->expects($this->once())
            ->method('text')
            ->with('<fg=yellow>Branch: feat/TPW-35</>');

        $logger = new Logger($io, $colors);
        $logger->gitText(Logger::VERBOSITY_NORMAL, 'Branch: feat/TPW-35');
    }

    public function testGitTextWithArrayAppliesGitColor(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $colors = ['git_message' => 'yellow'];
        $io->expects($this->once())
            ->method('text')
            ->with(['<fg=yellow>Line 1</>', '<fg=yellow>Line 2</>']);

        $logger = new Logger($io, $colors);
        $logger->gitText(Logger::VERBOSITY_NORMAL, ['Line 1', 'Line 2']);
    }

    public function testJiraWritelnFallsBackToDefaultColor(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(true);

        // No jira_message in colors
        $colors = [];
        $io->expects($this->once())
            ->method('writeln')
            ->with('<fg=blue>Fetching issue: TPW-35</>');

        $logger = new Logger($io, $colors);
        $logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, 'Fetching issue: TPW-35');
    }

    public function testGitWritelnFallsBackToDefaultColor(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(true);

        // No git_message in colors
        $colors = [];
        $io->expects($this->once())
            ->method('writeln')
            ->with('<fg=yellow>Branch created: feat/TPW-35</>');

        $logger = new Logger($io, $colors);
        $logger->gitWriteln(Logger::VERBOSITY_VERBOSE, 'Branch created: feat/TPW-35');
    }

    public function testGetCurrentVerbosityReturnsVeryVerbose(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(true);
        $io->method('isVerbose')->willReturn(true);

        $logger = new Logger($io, []);
        $reflection = new \ReflectionClass($logger);
        $method = $reflection->getMethod('getCurrentVerbosity');
        $method->setAccessible(true);

        $this->assertSame(Logger::VERBOSITY_VERY_VERBOSE, $method->invoke($logger));
    }
}
