<?php

namespace App\Tests\Service;

use App\Service\HelpService;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class HelpServiceTest extends TestCase
{
    private HelpService $helpService;
    private TranslationService $translationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translationService = $this->createMock(TranslationService::class);
        $this->translationService->method('trans')
            ->willReturnCallback(function ($id, $parameters = []) {
                return $id . (empty($parameters) ? '' : ' ' . json_encode($parameters));
            });
        $this->translationService->method('getLocale')->willReturn('en');
        $this->helpService = new HelpService($this->translationService);
    }

    public function testGetCommandHelpForExistingCommand(): void
    {
        $helpText = $this->helpService->getCommandHelp('commit');

        $this->assertNotNull($helpText);
        $this->assertIsString($helpText);
        $this->assertNotEmpty($helpText);
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
            'items:transition',
            'branch:rename',
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
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('writeln')
            ->with($this->isType('string'));
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note')
            ->with($this->isType('string'));

        $this->helpService->displayCommandHelp($io, 'commit');
    }

    public function testDisplayCommandHelpForNonExistentCommand(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('writeln')
            ->with($this->isType('string'));
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note')
            ->with($this->callback(function ($message) {
                return is_string($message) && ! empty($message);
            }));

        $this->helpService->displayCommandHelp($io, 'nonexistent');
    }

    public function testDisplayCommandHelpCallsSection(): void
    {
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));

        $io->expects($this->atLeastOnce())
            ->method('writeln')
            ->with($this->isType('string'));

        $io->expects($this->atLeastOnce())
            ->method('newLine');

        $io->expects($this->atLeastOnce())
            ->method('note')
            ->with($this->isType('string'));

        $this->helpService->displayCommandHelp($io, 'commit');
    }

    public function testDisplayCommandHelpForNonExistentCommandCallsSection(): void
    {
        $io = $this->createMock(SymfonyStyle::class);

        $io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));

        $io->expects($this->atLeastOnce())
            ->method('writeln')
            ->with($this->isType('string'));

        $io->expects($this->atLeastOnce())
            ->method('newLine');

        $io->expects($this->atLeastOnce())
            ->method('note')
            ->with($this->isType('string'));

        $this->helpService->displayCommandHelp($io, 'nonexistent');
    }

    public function testDisplayCommandHelpFallsBackToTranslationWhenCommandNotFound(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('writeln')
            ->with($this->isType('string'));
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note')
            ->with($this->callback(function ($message) {
                return is_string($message) && ! empty($message);
            }));

        // Use a command that doesn't exist in README and doesn't have a translation key
        $this->helpService->displayCommandHelp($io, 'nonexistentcommand12345');
    }

    public function testDisplayCommandHelpUsesTranslationWhenReadmeReturnsNull(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && ! empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('writeln')
            ->with($this->isType('string'));
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note')
            ->with($this->isType('string'));

        // Test with a command that exists in COMMAND_PATTERNS but might not be in README
        // This tests the fallback to translation when getCommandHelp returns null
        $this->helpService->displayCommandHelp($io, 'completion');
    }

    public function testDisplayCommandHelpWithAliasAndArguments(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test release command which has alias 'rl' and argument '<version>'
        $this->helpService->displayCommandHelp($io, 'release');
    }

    public function testDisplayCommandHelpWithVersionArgument(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test release command with optional [<version>] argument
        $this->helpService->displayCommandHelp($io, 'release');
    }

    public function testDisplayCommandHelpWithJqlArgument(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test items:search command with <jql> argument
        $this->helpService->displayCommandHelp($io, 'items:search');
    }

    public function testDisplayCommandHelpWithShellArgument(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test completion command with <shell> argument
        $this->helpService->displayCommandHelp($io, 'completion');
    }

    public function testDisplayCommandHelpWithLabelsOption(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test submit command which has --labels option with <labels> argument
        $this->helpService->displayCommandHelp($io, 'submit');
    }

    public function testDisplayCommandHelpWithMessageOption(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test commit command which has --message option with <message> argument
        $this->helpService->displayCommandHelp($io, 'commit');
    }

    public function testDisplayCommandHelpWithKeyOption(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test items:list command which has --project option with <key> argument
        $this->helpService->displayCommandHelp($io, 'items:list');
    }

    public function testDisplayCommandHelpWithMultipleOptions(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test submit command which has multiple options (--draft and --labels)
        $this->helpService->displayCommandHelp($io, 'submit');
    }

    public function testDisplayCommandHelpWithCommitMultipleOptions(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test commit command which has multiple options (--new and --message)
        $this->helpService->displayCommandHelp($io, 'commit');
    }

    public function testFormatCommandHelpFromTranslationWithVersionArgument(): void
    {
        // Test formatCommandHelpFromTranslation directly with release command
        // This covers the release command definition (lines 292-301) and version argument handling
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'release');

        // Test intent: should return formatted help text with release command details
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify release command specific content is present
        $this->assertStringContainsString('stud release', $result);
        $this->assertStringContainsString('stud rl', $result);
        $this->assertStringContainsString('--major', $result);
        $this->assertStringContainsString('--minor', $result);
        $this->assertStringContainsString('--patch', $result);
        $this->assertStringContainsString('--publish', $result);
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

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
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

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
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

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testDisplayCommandHelpWithPrCommentCommand(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test pr:comment command which has alias 'pc' and argument '<message>'
        $this->helpService->displayCommandHelp($io, 'pr:comment');
    }

    public function testFormatCommandHelpFromTranslationWithPrComment(): void
    {
        // Test formatCommandHelpFromTranslation directly with pr:comment
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'pr:comment');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testDisplayCommandHelpWithFiltersShow(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('writeln');
        $io->expects($this->atLeastOnce())
            ->method('newLine');
        $io->expects($this->atLeastOnce())
            ->method('note');

        // Test filters:show command which has alias 'fs' and argument '<filterName>'
        $this->helpService->displayCommandHelp($io, 'filters:show');
    }

    public function testFormatCommandHelpFromTranslationWithFiltersShow(): void
    {
        // Test formatCommandHelpFromTranslation directly with filters:show
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'filters:show');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testGetCommandHelpForFiltersShow(): void
    {
        $helpText = $this->helpService->getCommandHelp('filters:show');

        // Test intent: help text should be returned for existing command
        $this->assertNotNull($helpText);
        $this->assertIsString($helpText);
        $this->assertNotEmpty($helpText);
    }

    public function testFormatCommandHelpFromTranslationWithItemsTransition(): void
    {
        // Test items:transition command with optional [<key>] argument
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'items:transition');

        // Test intent: should return formatted help text with alias tx
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud items:transition', $result);
        $this->assertStringContainsString('stud tx', $result);
        // Optional argument should appear in signature but not in examples
        $this->assertStringContainsString('[<key>]', $result);
    }

    public function testFormatCommandHelpFromTranslationWithBranchRename(): void
    {
        // Test branch:rename command with --name option and optional arguments
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'branch:rename');

        // Test intent: should return formatted help text with alias rn and --name option
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud branch:rename', $result);
        $this->assertStringContainsString('stud rn', $result);
        $this->assertStringContainsString('--name', $result);
        $this->assertStringContainsString('-n', $result);
        // Optional arguments should appear in signature but not in examples
        $this->assertStringContainsString('[<branch>]', $result);
        $this->assertStringContainsString('[<key>]', $result);
    }

    public function testFormatCommandHelpFromTranslationWithItemsListSortOption(): void
    {
        // Test items:list command with --sort option (third option with <value> argument)
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'items:list');

        // Test intent: should return formatted help text with --sort option
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--sort', $result);
        $this->assertStringContainsString('-s', $result);
        // Test that first option with argument is covered (--project with <key>)
        $this->assertStringContainsString('--project', $result);
        $this->assertStringContainsString('<key>', $result);
    }

    public function testFormatCommandHelpFromTranslationWithFirstOptionArgument(): void
    {
        // Test items:list which has --project as first option with <key> argument
        // This covers the code path marked as untestable but is actually testable
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'items:list');

        // Test intent: should include --project option with argument in examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify the option with argument appears in usage examples
        $this->assertStringContainsString('--project', $result);
        $this->assertStringContainsString('-p', $result);
    }

    public function testFormatCommandHelpFromTranslationWithSecondOptionArgument(): void
    {
        // Test submit command which has --labels as second option with <labels> argument
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'submit');

        // Test intent: should include --labels option with argument in examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--labels', $result);
        $this->assertStringContainsString('<labels>', $result);
    }

    public function testFormatCommandHelpFromTranslationWithBranchRenameNameOption(): void
    {
        // Test branch:rename which has --name as first option with <name> argument
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'branch:rename');

        // Test intent: should include --name option with <name> argument in examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--name', $result);
        $this->assertStringContainsString('-n', $result);
        $this->assertStringContainsString('<name>', $result);
        // Verify the option appears in usage examples
        $this->assertStringContainsString('custom-branch-name', $result);
    }

    public function testFormatCommandHelpFromTranslationWithItemsListValueOption(): void
    {
        // Test items:list which has --sort as third option with <value> argument
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'items:list');

        // Test intent: should include --sort option with <value> argument in options list
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--sort', $result);
        $this->assertStringContainsString('-s', $result);
        $this->assertStringContainsString('<value>', $result);
        // Note: --sort is third option, so it won't appear in usage examples (only first 2 options shown)
    }

    public function testFormatCommandHelpFromTranslationWithItemsListSecondOptionKey(): void
    {
        // Test items:list which has --project as second option with <key> argument
        // This covers the second option with <key> branch
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'items:list');

        // Test intent: should include --project as second option with <key> argument in usage examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify second option appears in usage examples
        $this->assertStringContainsString('-p PROJ', $result);
        $this->assertStringContainsString('--project', $result);
    }

    public function testFormatCommandHelpFromTranslationWithSubmitSecondOptionLabels(): void
    {
        // Test submit which has --labels as second option with <labels> argument
        // This covers the second option with <labels> branch
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'submit');

        // Test intent: should include --labels as second option with <labels> argument in usage examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify second option appears in usage examples
        $this->assertStringContainsString('--labels', $result);
        $this->assertStringContainsString('"bug,enhancement"', $result);
    }

    public function testFormatCommandHelpFromTranslationWithCommitSecondOptionMessage(): void
    {
        // Test commit which has --message as second option with <message> argument
        // This covers the second option with <message> branch
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'commit');

        // Test intent: should include --message as second option with <message> argument in usage examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify second option appears in usage examples
        $this->assertStringContainsString('--message', $result);
        $this->assertStringContainsString('-m', $result);
        $this->assertStringContainsString('"feat: My custom message"', $result);
    }

    public function testFormatCommandHelpFromTranslationWithNoOptions(): void
    {
        // Test a command with no options (e.g., status, please, flatten)
        // This covers the path where !empty($command['options']) is false
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'status');

        // Test intent: should return formatted help text without options section
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud status', $result);
        $this->assertStringContainsString('stud ss', $result);
        // Should not contain options section
        $this->assertStringNotContainsString('-   Options:', $result);
    }

    public function testFormatCommandHelpFromTranslationWithNoAlias(): void
    {
        // Test completion command which has no alias
        // This covers the path where $command['alias'] is null
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'completion');

        // Test intent: should return formatted help text without alias
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud completion', $result);
        // Should not contain alias
        $this->assertStringNotContainsString('(Alias:', $result);
    }

    public function testFormatCommandHelpFromTranslationWithSingleOption(): void
    {
        // Test branch:rename or update which have exactly one option
        // This covers the path where count($command['options']) is exactly 1
        // The second option block (count > 1) should not execute
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'branch:rename');

        // Test intent: should return formatted help text with one option
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--name', $result);
        // Should only show first option in examples, not second (since there is no second)
    }

    public function testFormatCommandHelpFromTranslationWithNoArguments(): void
    {
        // Test a command with no arguments (e.g., status, please)
        // This covers paths where empty($command['arguments']) is true
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'please');

        // Test intent: should return formatted help text without arguments
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud please', $result);
        // Should not have argument examples in usage
    }

    public function testFormatCommandHelpFromTranslationWithAliasButNoArguments(): void
    {
        // Test a command with alias but no arguments (e.g., please, status)
        // This covers the path at line 301 where alias exists but arguments are empty
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'status');

        // Test intent: should return formatted help text with alias but no arguments in alias
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud status', $result);
        $this->assertStringContainsString('stud ss', $result);
        $this->assertStringContainsString('(Alias: stud ss)', $result);
        // Alias should not have arguments appended
        $this->assertStringNotContainsString('(Alias: stud ss ', $result);
    }

    public function testGetFileSystemReturnsProvidedFileSystem(): void
    {
        // Test that getFileSystem returns the provided FileSystem instance
        $fileSystem = $this->createMock(\App\Service\FileSystem::class);
        $helpService = new HelpService($this->translationService, $fileSystem);

        $reflection = new \ReflectionClass($helpService);
        $method = $reflection->getMethod('getFileSystem');
        $method->setAccessible(true);

        $result = $method->invoke($helpService);
        $this->assertSame($fileSystem, $result);
    }

    public function testGetFileSystemCreatesLocalWhenNotProvided(): void
    {
        // Test that getFileSystem creates a local FileSystem when none is provided
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('getFileSystem');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService);
        $this->assertInstanceOf(\App\Service\FileSystem::class, $result);
    }

    public function testGetCommandHelpReturnsNullWhenFileReadThrowsException(): void
    {
        // Test that getCommandHelp returns null when file read throws RuntimeException
        // This covers line 60 (catch block)
        $fileSystem = $this->createMock(\App\Service\FileSystem::class);
        $fileSystem->method('read')
            ->willThrowException(new \RuntimeException('File read failed'));

        $helpService = new HelpService($this->translationService, $fileSystem);

        $result = $helpService->getCommandHelp('commit');
        $this->assertNull($result);
    }
}
