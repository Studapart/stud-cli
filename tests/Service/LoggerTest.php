<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\WorkflowChannel;
use App\Service\ColorHelper;
use App\Service\Logger;
use App\Service\OutputVerbosityGate;
use App\Service\WorkflowChannelColorizer;
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

        $logger = $this->buildLogger($io);
        $logger->error(Logger::VERBOSITY_NORMAL, 'Test error');
    }

    public function testErrorShownWhenQuiet(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(true);

        $io->expects($this->once())
            ->method('error')
            ->with('Test error');

        $logger = $this->buildLogger($io);
        $logger->error(Logger::VERBOSITY_NORMAL, 'Test error');
    }

    public function testSuppressOutputPreventsHumanLoggerOutput(): void
    {
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->never())->method('error');
        $io->expects($this->never())->method('warning');
        $io->expects($this->never())->method('success');
        $io->expects($this->never())->method('writeln');
        $io->expects($this->never())->method('text');

        $logger = $this->buildLogger($io, suppressOutput: true);
        $logger->error(Logger::VERBOSITY_NORMAL, 'Test error');
        $logger->errorWithDetails(Logger::VERBOSITY_NORMAL, 'Test error', 'Technical details');
        $logger->warning(Logger::VERBOSITY_NORMAL, 'Test warning');
        $logger->success(Logger::VERBOSITY_NORMAL, 'Test success');
        $logger->text(Logger::VERBOSITY_NORMAL, 'Test text');
        $logger->rawValue('raw');
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

        $logger = $this->buildLogger($io);
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

        $logger = $this->buildLogger($io);
        $logger->note(Logger::VERBOSITY_NORMAL, 'Test note');
    }

    public function testNoteSuppressedWhenQuiet(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(true);

        $io->expects($this->never())
            ->method('note');

        $logger = $this->buildLogger($io);
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

        $logger = $this->buildLogger($io);
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

        $logger = $this->buildLogger($io);
        $logger->text(Logger::VERBOSITY_NORMAL, 'Test text');
    }

    public function testRawValueShownWhenQuiet(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(true);

        $io->expects($this->once())
            ->method('writeln')
            ->with('SCI');

        $logger = $this->buildLogger($io);
        $logger->rawValue('SCI');
    }

    public function testRawValueShownWhenNotQuiet(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('writeln')
            ->with('raw');

        $logger = $this->buildLogger($io);
        $logger->rawValue('raw');
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

        $logger = $this->buildLogger($io);
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

        $logger = $this->buildLogger($io);
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

        $logger = $this->buildLogger($io);
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

        $logger = $this->buildLogger($io);
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

        $logger = $this->buildLogger($io, $colors);
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

        $logger = $this->buildLogger($io, $colors);
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

        $logger = $this->buildLogger($io, $colors);
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

        $logger = $this->buildLogger($io, $colors);
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

        $logger = $this->buildLogger($io, $colors);
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

        $logger = $this->buildLogger($io, $colors);
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

        $logger = $this->buildLogger($io, $colors);
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

        $logger = $this->buildLogger($io, $colors);
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

        $logger = $this->buildLogger($io, $colors);
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

        $logger = $this->buildLogger($io, $colors);
        $logger->gitWriteln(Logger::VERBOSITY_VERBOSE, 'Branch created: feat/TPW-35');
    }

    public function testTitleRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('title')
            ->with('Test Title');

        $logger = $this->buildLogger($io);
        $logger->title(Logger::VERBOSITY_NORMAL, 'Test Title');
    }

    public function testListingRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $elements = ['Item 1', 'Item 2', 'Item 3'];
        $io->expects($this->once())
            ->method('listing')
            ->with($elements);

        $logger = $this->buildLogger($io);
        $logger->listing(Logger::VERBOSITY_NORMAL, $elements);
    }

    public function testCommentRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('comment')
            ->with('Test comment');

        $logger = $this->buildLogger($io);
        $logger->comment(Logger::VERBOSITY_NORMAL, 'Test comment');
    }

    public function testCommentWithArrayRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $messages = ['Comment 1', 'Comment 2'];
        $io->expects($this->once())
            ->method('comment')
            ->with($messages);

        $logger = $this->buildLogger($io);
        $logger->comment(Logger::VERBOSITY_NORMAL, $messages);
    }

    public function testInfoRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('info')
            ->with('Test info');

        $logger = $this->buildLogger($io);
        $logger->info(Logger::VERBOSITY_NORMAL, 'Test info');
    }

    public function testInfoWithArrayRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $messages = ['Info 1', 'Info 2'];
        $io->expects($this->once())
            ->method('info')
            ->with($messages);

        $logger = $this->buildLogger($io);
        $logger->info(Logger::VERBOSITY_NORMAL, $messages);
    }

    public function testCautionRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('caution')
            ->with('Test caution');

        $logger = $this->buildLogger($io);
        $logger->caution(Logger::VERBOSITY_NORMAL, 'Test caution');
    }

    public function testCautionWithArrayRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $messages = ['Caution 1', 'Caution 2'];
        $io->expects($this->once())
            ->method('caution')
            ->with($messages);

        $logger = $this->buildLogger($io);
        $logger->caution(Logger::VERBOSITY_NORMAL, $messages);
    }

    public function testTableRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $headers = ['Header 1', 'Header 2'];
        $rows = [['Row 1 Col 1', 'Row 1 Col 2'], ['Row 2 Col 1', 'Row 2 Col 2']];
        $io->expects($this->once())
            ->method('table')
            ->with($headers, $rows);

        $logger = $this->buildLogger($io);
        $logger->table(Logger::VERBOSITY_NORMAL, $headers, $rows);
    }

    public function testHorizontalTableRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $headers = ['Header 1', 'Header 2'];
        $rows = [['Row 1 Col 1', 'Row 1 Col 2'], ['Row 2 Col 1', 'Row 2 Col 2']];
        $io->expects($this->once())
            ->method('horizontalTable')
            ->with($headers, $rows);

        $logger = $this->buildLogger($io);
        $logger->horizontalTable(Logger::VERBOSITY_NORMAL, $headers, $rows);
    }

    public function testDefinitionListRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $list = ['Key 1' => 'Value 1', 'Key 2' => 'Value 2'];
        $io->expects($this->once())
            ->method('definitionList')
            ->with(...array_map(fn ($k, $v) => [$k, $v], array_keys($list), $list));

        $logger = $this->buildLogger($io);
        $logger->definitionList(Logger::VERBOSITY_NORMAL, ...array_map(fn ($k, $v) => [$k, $v], array_keys($list), $list));
    }

    public function testNewLineRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('newLine')
            ->with(2);

        $logger = $this->buildLogger($io);
        $logger->newLine(Logger::VERBOSITY_NORMAL, 2);
    }

    public function testRegisterStylesDelegatesIoToColorHelper(): void
    {
        $io = $this->createStub(SymfonyStyle::class);
        $colorHelper = $this->createMock(ColorHelper::class);
        $colorHelper->expects($this->once())->method('registerStyles')->with($io);

        $this->buildLogger($io)->registerStyles($colorHelper);
    }

    public function testSectionRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())->method('section')->with('Section');

        $logger = $this->buildLogger($io);
        $logger->section(Logger::VERBOSITY_NORMAL, 'Section');
        $logger->section(Logger::VERBOSITY_VERBOSE, 'Hidden section');
    }

    public function testTextWithArrayAppliesChannelColorToEachLine(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);

        $io->expects($this->once())
            ->method('text')
            ->with(['<fg=blue>one</>', '<fg=blue>two</>']);

        $this->buildLogger($io)->text(Logger::VERBOSITY_NORMAL, ['one', 'two'], WorkflowChannel::Jira);
    }

    public function testConfirmReturnsAnswer(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('confirm')
            ->with('Continue?', false)
            ->willReturn(true);

        $this->assertTrue($this->buildLogger($io)->confirm('Continue?', false));
    }

    public function testChoiceReturnsSelection(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('choice')
            ->with('Pick one', ['a', 'b'], 'a', false)
            ->willReturn('b');

        $this->assertSame('b', $this->buildLogger($io)->choice('Pick one', ['a', 'b'], 'a'));
    }

    public function testAskHiddenReturnsString(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('askHidden')
            ->with('Enter token:', null)
            ->willReturn('secret_token');

        $logger = $this->buildLogger($io);
        $result = $logger->askHidden('Enter token:');

        $this->assertSame('secret_token', $result);
    }

    public function testAskHiddenReturnsNull(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('askHidden')
            ->with('Enter token:', null)
            ->willReturn(null);

        $logger = $this->buildLogger($io);
        $result = $logger->askHidden('Enter token:');

        $this->assertNull($result);
    }

    public function testAskReturnsString(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('ask')
            ->with('Enter value:', 'default', null)
            ->willReturn('user_input');

        $logger = $this->buildLogger($io);
        $result = $logger->ask('Enter value:', 'default');

        $this->assertSame('user_input', $result);
    }

    public function testAskReturnsNull(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('ask')
            ->with('Enter value:', null, null)
            ->willReturn(null);

        $logger = $this->buildLogger($io);
        $result = $logger->ask('Enter value:');

        $this->assertNull($result);
    }

    public function testErrorWithDetailsDisplaysBothMessages(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('error')
            ->with('User-friendly error message');

        $io->expects($this->once())
            ->method('text')
            ->with(['', ' Technical details: Technical error details']);

        $logger = $this->buildLogger($io);
        $logger->errorWithDetails(Logger::VERBOSITY_NORMAL, 'User-friendly error message', 'Technical error details');
    }

    public function testErrorWithDetailsRespectsVerbosity(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->never())
            ->method('error');

        $io->expects($this->never())
            ->method('text');

        $logger = $this->buildLogger($io);
        $logger->errorWithDetails(Logger::VERBOSITY_VERBOSE, 'User-friendly error message', 'Technical error details');
    }

    public function testErrorWithDetailsShownWhenQuiet(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(true);

        $io->expects($this->once())
            ->method('error')
            ->with('User-friendly error message');

        $io->expects($this->once())
            ->method('text')
            ->with(['', ' Technical details: Technical error details']);

        $logger = $this->buildLogger($io);
        $logger->errorWithDetails(Logger::VERBOSITY_NORMAL, 'User-friendly error message', 'Technical error details');
    }

    public function testErrorWithDetailsSkipsEmptyTechnicalDetails(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('error')
            ->with('User-friendly error message');

        $io->expects($this->never())
            ->method('text');

        $logger = $this->buildLogger($io);
        $logger->errorWithDetails(Logger::VERBOSITY_NORMAL, 'User-friendly error message', '');
    }

    public function testErrorWithDetailsSkipsWhitespaceOnlyTechnicalDetails(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);

        $io->expects($this->once())
            ->method('error')
            ->with('User-friendly error message');

        $io->expects($this->never())
            ->method('text');

        $logger = $this->buildLogger($io);
        $logger->errorWithDetails(Logger::VERBOSITY_NORMAL, 'User-friendly error message', '   ');
    }

    /**
     * @param array<string, string> $colors
     */
    private function buildLogger(SymfonyStyle $io, array $colors = [], bool $suppressOutput = false): Logger
    {
        return new Logger($io, new WorkflowChannelColorizer($colors), new OutputVerbosityGate($io, $suppressOutput));
    }
}
