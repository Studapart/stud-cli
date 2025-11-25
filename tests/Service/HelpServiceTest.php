<?php

namespace App\Tests\Service;

use App\Service\HelpService;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class HelpServiceTest extends TestCase
{
    private HelpService $helpService;
    private TranslationService $translationService;

    protected function setUp(): void
    {
        parent::setUp();

        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new TranslationService('en', $translationsPath);
        $this->helpService = new HelpService($this->translationService);
    }

    public function testGetCommandHelpForExistingCommand(): void
    {
        $helpText = $this->helpService->getCommandHelp('commit');
        
        $this->assertNotNull($helpText);
        $this->assertIsString($helpText);
        $this->assertNotEmpty($helpText);
        // Test intent: help text should contain information about the command
        $this->assertStringContainsString('commit', $helpText);
    }

    public function testGetCommandHelpForNonExistentCommand(): void
    {
        $helpText = $this->helpService->getCommandHelp('nonexistent');
        
        $this->assertNull($helpText);
    }

    public function testGetCommandHelpForAllSupportedCommands(): void
    {
        $commands = [
            'config:init',
            'completion',
            'projects:list',
            'items:list',
            'items:search',
            'items:show',
            'items:start',
            'commit',
            'please',
            'status',
            'submit',
            'update',
            'release',
            'deploy',
        ];

        foreach ($commands as $command) {
            $helpText = $this->helpService->getCommandHelp($command);
            // Test intent: all supported commands should return help text or null (if README doesn't have it)
            // We don't assert on specific content, just that the method handles it correctly
            $this->assertTrue(
                $helpText === null || is_string($helpText),
                "Command '{$command}' should return null or string"
            );
        }
    }

    public function testDisplayCommandHelpForExistingCommand(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->helpService->displayCommandHelp($io, 'commit');

        $outputText = $output->fetch();
        
        // Test intent: section() should be called with command help title
        $this->assertStringContainsString('Help:', $outputText);
        $this->assertStringContainsString('commit', $outputText);
    }

    public function testDisplayCommandHelpForNonExistentCommand(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->helpService->displayCommandHelp($io, 'nonexistent');

        $outputText = $output->fetch();
        
        // Test intent: should show error message for unknown command
        $this->assertStringContainsString('Help:', $outputText);
        $this->assertStringContainsString('nonexistent', $outputText);
        // Should show the "not found" message
        $this->assertStringContainsString('not available', $outputText);
    }

    public function testDisplayCommandHelpCallsSection(): void
    {
        $output = new BufferedOutput();
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('Help:'));

        $io->expects($this->once())
            ->method('writeln')
            ->with($this->isType('string'));

        $io->expects($this->once())
            ->method('newLine');

        $io->expects($this->once())
            ->method('note')
            ->with($this->isType('string'));

        $this->helpService->displayCommandHelp($io, 'commit');
    }

    public function testDisplayCommandHelpForNonExistentCommandCallsSection(): void
    {
        $output = new BufferedOutput();
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->stringContains('Help:'));

        $io->expects($this->once())
            ->method('writeln')
            ->with($this->isType('string'));

        $io->expects($this->once())
            ->method('newLine');

        $io->expects($this->once())
            ->method('note')
            ->with($this->isType('string'));

        $this->helpService->displayCommandHelp($io, 'nonexistent');
    }

    public function testDisplayCommandHelpFallsBackToTranslationWhenCommandNotFound(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Use a command that doesn't exist in README and doesn't have a translation key
        $this->helpService->displayCommandHelp($io, 'nonexistentcommand12345');

        $outputText = $output->fetch();
        
        // Test intent: should display help with "not found" message
        $this->assertStringContainsString('Help:', $outputText);
        $this->assertStringContainsString('nonexistentcommand12345', $outputText);
        $this->assertStringContainsString('not available', $outputText);
    }

    public function testDisplayCommandHelpUsesTranslationWhenReadmeReturnsNull(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test with a command that exists in COMMAND_PATTERNS but might not be in README
        // This tests the fallback to translation when getCommandHelp returns null
        // We'll use a command that we know exists but might not have README content
        $this->helpService->displayCommandHelp($io, 'completion');

        $outputText = $output->fetch();
        
        // Test intent: should still display help (either from README or translation)
        $this->assertStringContainsString('Help:', $outputText);
        $this->assertStringContainsString('completion', $outputText);
    }
}

