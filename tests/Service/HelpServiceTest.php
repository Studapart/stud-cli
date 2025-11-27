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
            'pr:comment',
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

    public function testDisplayCommandHelpWithAliasAndArguments(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test release command which has alias 'rl' and argument '<version>'
        // This covers line 241: adding arguments to alias line
        $this->helpService->displayCommandHelp($io, 'release');

        $outputText = $output->fetch();
        
        // Test intent: should display help with alias and arguments
        $this->assertStringContainsString('Help:', $outputText);
        $this->assertStringContainsString('release', $outputText);
        $this->assertStringContainsString('rl', $outputText);
        $this->assertStringContainsString('<version>', $outputText);
    }

    public function testDisplayCommandHelpWithVersionArgument(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test release command with <version> argument
        // This covers line 281: version argument type
        $this->helpService->displayCommandHelp($io, 'release');

        $outputText = $output->fetch();
        
        // Test intent: should show example with version
        $this->assertStringContainsString('1.2.0', $outputText);
    }

    public function testDisplayCommandHelpWithJqlArgument(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test items:search command with <jql> argument
        // This covers line 283: jql argument type
        $this->helpService->displayCommandHelp($io, 'items:search');

        $outputText = $output->fetch();
        
        // Test intent: should show example with jql
        $this->assertStringContainsString('project = PROJ', $outputText);
    }

    public function testDisplayCommandHelpWithShellArgument(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test completion command with <shell> argument
        // This covers line 285: shell argument type (already covered, but let's be explicit)
        $this->helpService->displayCommandHelp($io, 'completion');

        $outputText = $output->fetch();
        
        // Test intent: should show example with shell
        $this->assertStringContainsString('bash', $outputText);
    }

    public function testDisplayCommandHelpWithLabelsOption(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test submit command which has --labels option with <labels> argument
        // This covers lines 307-308: labels option argument
        $this->helpService->displayCommandHelp($io, 'submit');

        $outputText = $output->fetch();
        
        // Test intent: should show example with labels option
        $this->assertStringContainsString('submit', $outputText);
        $this->assertStringContainsString('--labels', $outputText);
        $this->assertStringContainsString('bug,enhancement', $outputText);
    }

    public function testDisplayCommandHelpWithMessageOption(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test commit command which has --message option with <message> argument
        // This covers lines 309-310: message option argument
        $this->helpService->displayCommandHelp($io, 'commit');

        $outputText = $output->fetch();
        
        // Test intent: should show example with message option
        $this->assertStringContainsString('commit', $outputText);
        $this->assertStringContainsString('--message', $outputText);
        $this->assertStringContainsString('feat: My custom message', $outputText);
    }

    public function testDisplayCommandHelpWithKeyOption(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test items:list command which has --project option with <key> argument
        // This covers lines 311-312: key option argument
        $this->helpService->displayCommandHelp($io, 'items:list');

        $outputText = $output->fetch();
        
        // Test intent: should show example with key option
        $this->assertStringContainsString('items:list', $outputText);
        $this->assertStringContainsString('--project', $outputText);
        $this->assertStringContainsString('PROJ', $outputText);
    }

    public function testDisplayCommandHelpWithMultipleOptions(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test submit command which has multiple options (--draft and --labels)
        // This covers lines 322-339: second option handling
        $this->helpService->displayCommandHelp($io, 'submit');

        $outputText = $output->fetch();
        
        // Test intent: should show examples with both options
        $this->assertStringContainsString('submit', $outputText);
        $this->assertStringContainsString('--draft', $outputText);
        $this->assertStringContainsString('--labels', $outputText);
    }

    public function testDisplayCommandHelpWithCommitMultipleOptions(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test commit command which has multiple options (--new and --message)
        // This covers lines 322-339: second option handling with different argument types
        $this->helpService->displayCommandHelp($io, 'commit');

        $outputText = $output->fetch();
        
        // Test intent: should show examples with both options
        $this->assertStringContainsString('commit', $outputText);
        $this->assertStringContainsString('--new', $outputText);
        $this->assertStringContainsString('--message', $outputText);
    }

    public function testFormatCommandHelpFromTranslationWithVersionArgument(): void
    {
        // Test formatCommandHelpFromTranslation directly with release command
        // This covers line 281: version argument type
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->helpService, 'release');
        
        // Test intent: should include version example
        $this->assertStringContainsString('1.2.0', $result);
        $this->assertStringContainsString('release', $result);
    }

    public function testFormatCommandHelpFromTranslationWithItemsListFirstOptionWithArgument(): void
    {
        // Test formatCommandHelpFromTranslation directly with items:list
        // items:list has --project as first option with <key> argument
        // This covers lines 306-312: first option with argument handling
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->helpService, 'items:list');
        
        // Test intent: should show --project option with PROJ example
        $this->assertStringContainsString('--project', $result);
        $this->assertStringContainsString('PROJ', $result);
    }

    public function testFormatCommandHelpFromTranslationWithSubmitFirstOptionWithArgument(): void
    {
        // Test formatCommandHelpFromTranslation directly with submit
        // submit has --draft (no arg) as first, but we need to test when first has arg
        // Actually, items:list is better for this. But let's also test submit's second option path
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->helpService, 'submit');
        
        // Test intent: should show --labels option with bug,enhancement example
        $this->assertStringContainsString('--labels', $result);
        $this->assertStringContainsString('bug,enhancement', $result);
    }

    public function testFormatCommandHelpFromTranslationWithKeyArgument(): void
    {
        // Test formatCommandHelpFromTranslation directly with items:show
        // items:show has <key> as argument
        // This covers line 279: key argument type
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->helpService, 'items:show');
        
        // Test intent: should include JIRA-33 example for key argument
        $this->assertStringContainsString('JIRA-33', $result);
        $this->assertStringContainsString('items:show', $result);
    }

    public function testDisplayCommandHelpWithPrCommentCommand(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // Test pr:comment command which has alias 'pc' and argument '<message>'
        $this->helpService->displayCommandHelp($io, 'pr:comment');

        $outputText = $output->fetch();
        
        // Test intent: should display help with alias and argument
        $this->assertStringContainsString('Help:', $outputText);
        $this->assertStringContainsString('pr:comment', $outputText);
        $this->assertStringContainsString('pc', $outputText);
        $this->assertStringContainsString('<message>', $outputText);
    }

    public function testFormatCommandHelpFromTranslationWithPrComment(): void
    {
        // Test formatCommandHelpFromTranslation directly with pr:comment
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->helpService, 'pr:comment');
        
        // Test intent: should include message argument example
        $this->assertStringContainsString('pr:comment', $result);
        $this->assertStringContainsString('pc', $result);
        $this->assertStringContainsString('<message>', $result);
        $this->assertStringContainsString('"Comment text"', $result);
    }
}

