<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CommandMap;
use App\Service\FileSystem;
use App\Service\HelpService;
use App\Service\Logger;
use App\Service\TranslationService;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HelpServiceTest extends TestCase
{
    private HelpService $helpService;
    private TranslationService $translationService;
    private FileSystem $fileSystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translationService = $this->createMock(TranslationService::class);
        $this->translationService->method('trans')
            ->willReturnCallback(function ($id, $parameters = []) {
                return $id . (empty($parameters) ? '' : ' ' . json_encode($parameters));
            });
        $this->translationService->method('getLocale')->willReturn('en');

        // Create in-memory filesystem
        $adapter = new InMemoryFilesystemAdapter();
        $flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($flysystem);

        $readmeContent = file_get_contents(__DIR__ . '/../Fixtures/readme/minimal-commands.md');
        $flysystem->write('README.md', $readmeContent);

        $this->helpService = new HelpService($this->translationService, $this->fileSystem);
    }

    public function testGetCommandHelpHandlesConciseReadme(): void
    {
        $helpText = $this->helpService->getCommandHelp('commit');

        $this->assertIsString($helpText);
        $this->assertStringStartsWith('stud commit', $helpText);
    }

    public function testGetCommandHelpForNonExistentCommand(): void
    {
        $helpText = $this->helpService->getCommandHelp('nonexistent');

        $this->assertNull($helpText);
    }

    public function testGetCommandHelpExtractsCommandSectionFromDetailedReadme(): void
    {
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->method('read')->willReturn(<<<'MD'
# Commands

- **`stud commit`** (Alias: `stud co`)
    - **Description:** Create a `commit`.
    - **Usage:**
        ```bash
        stud commit
        ```
- **`stud submit`** (Alias: `stud su`)
    - **Description:** Submit work.
MD);
        $helpService = new HelpService($this->translationService, $fileSystem);

        $helpText = $helpService->getCommandHelp('commit');

        $this->assertIsString($helpText);
        $this->assertStringContainsString('stud commit', $helpText);
        $this->assertStringContainsString('Description: Create a commit.', $helpText);
        $this->assertStringNotContainsString('stud submit', $helpText);
    }

    public function testShouldBreakHelpSectionForMarkdownHeader(): void
    {
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('shouldBreakHelpSection');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $this->assertTrue($method->invoke($this->helpService, '#### Git Workflow Commands'));
    }

    public function testGetCommandHelpForAllSupportedCommands(): void
    {
        $commands = [
            'config:init',
            'config:project-init',
            'completion',
            'projects:list',
            'projects:workflow',
            'projects:labels',
            'items:list',
            'items:search',
            'items:show',
            'items:download',
            'items:upload',
            'items:start',
            'items:transition',
            'branch:rename',
            'commit',
            'push',
            'please',
            'status',
            'submit',
            'pr:comment',
            'update',
            'release',
            'deploy',
        ];

        // The fixture holds only stud commit.
        foreach ($commands as $command) {
            $helpText = $this->helpService->getCommandHelp($command);
            if ($command === 'commit') {
                $this->assertIsString($helpText);

                continue;
            }

            $this->assertNull($helpText);
        }
    }

    public function testGeneralHelpCommandGroupsCoverCommandMap(): void
    {
        $groups = $this->helpService->getGeneralHelpCommandGroups();
        $rows = array_merge(...array_values($groups));
        $names = array_column($rows, 'name');

        $this->assertEqualsCanonicalizing(array_keys(CommandMap::all()), $names);
    }

    public function testGeneralHelpCommandGroupsIncludePreviouslyMissingCommands(): void
    {
        $groups = $this->helpService->getGeneralHelpCommandGroups();
        $rows = array_merge(...array_values($groups));
        $rowsByName = [];
        foreach ($rows as $row) {
            $rowsByName[$row['name']] = $row;
        }

        foreach ([
            'items:download',
            'items:upload',
            'items:update',
            'branches:list',
            'branches:clean',
            'flatten',
            'switch',
            'sync',
            'cache:clear',
            'help',
            'update',
        ] as $commandName) {
            $this->assertArrayHasKey($commandName, $rowsByName);
            $this->assertSame(CommandMap::all()[$commandName]['alias'], $rowsByName[$commandName]['alias']);
            $this->assertNotSame('', $rowsByName[$commandName]['description']);
        }
    }

    #[DataProvider('displayCommandHelpProvider')]
    public function testDisplayCommandHelpRendersTranslatedHelp(string $command, string $commandToken): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('section')
            ->with(Logger::VERBOSITY_NORMAL, 'help.command_help_title {"command":"' . $command . '"}');
        $logger->expects($this->once())
            ->method('writeln')
            ->with(Logger::VERBOSITY_NORMAL, $this->stringContains('stud ' . $commandToken));
        $logger->expects($this->once())
            ->method('newLine')
            ->with(Logger::VERBOSITY_NORMAL);
        $logger->expects($this->once())
            ->method('note')
            ->with(Logger::VERBOSITY_NORMAL, 'help.see_readme_note');

        $this->helpService->displayCommandHelp($logger, $command);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function displayCommandHelpProvider(): iterable
    {
        yield 'testDisplayCommandHelpForExistingCommand' => ['commit', 'commit'];
        yield 'testDisplayCommandHelpCallsSection' => ['commit', 'commit'];
        yield 'testDisplayCommandHelpUsesTranslationWhenReadmeReturnsNull' => ['completion', 'completion'];
        yield 'testDisplayCommandHelpWithAliasAndArguments' => ['release', 'release'];
        yield 'testDisplayCommandHelpWithVersionArgument' => ['release', 'release'];
        yield 'testDisplayCommandHelpWithJqlArgument' => ['items:search', 'items:search'];
        yield 'testDisplayCommandHelpWithShellArgument' => ['completion', 'completion'];
        yield 'testDisplayCommandHelpWithLabelsOption' => ['submit', 'submit'];
        yield 'testDisplayCommandHelpWithMessageOption' => ['commit', 'commit'];
        yield 'testDisplayCommandHelpWithKeyOption' => ['items:list', 'items:list'];
        yield 'testDisplayCommandHelpWithMultipleOptions' => ['submit', 'submit'];
        yield 'testDisplayCommandHelpWithCommitMultipleOptions' => ['commit', 'commit'];
        yield 'testDisplayCommandHelpWithPrCommentCommand' => ['pr:comment', 'pr:comment'];
        yield 'testDisplayCommandHelpWithFiltersShow' => ['filters:show', 'filters:show'];
    }

    #[DataProvider('displayCommandHelpNotFoundProvider')]
    public function testDisplayCommandHelpRendersNotFound(string $command): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('section')
            ->with(Logger::VERBOSITY_NORMAL, 'help.command_help_title {"command":"' . $command . '"}');
        $logger->expects($this->once())
            ->method('writeln')
            ->with(Logger::VERBOSITY_NORMAL, 'help.command_not_found {"command":"' . $command . '"}');
        $logger->expects($this->once())
            ->method('newLine')
            ->with(Logger::VERBOSITY_NORMAL);
        $logger->expects($this->once())
            ->method('note')
            ->with(Logger::VERBOSITY_NORMAL, 'help.see_readme_note');

        $this->helpService->displayCommandHelp($logger, $command);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function displayCommandHelpNotFoundProvider(): iterable
    {
        yield 'testDisplayCommandHelpForNonExistentCommand' => ['nonexistent'];
        yield 'testDisplayCommandHelpForNonExistentCommandCallsSection' => ['nonexistent'];
        yield 'testDisplayCommandHelpFallsBackToTranslationWhenCommandNotFound' => ['nonexistentcommand12345'];
    }

    public function testFormatCommandHelpFromTranslationWithVersionArgument(): void
    {
        // Test formatCommandHelpFromTranslation directly with release command
        // This covers the release command definition (lines 292-301) and version argument handling
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'items:list');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud items:list', $result);
        $this->assertStringContainsString('stud ls', $result);
        $this->assertStringContainsString('--project <key> or -p <key>', $result);
    }

    public function testFormatCommandHelpFromTranslationWithSubmitFirstOptionWithArgument(): void
    {
        // Test formatCommandHelpFromTranslation directly with submit
        // submit has --draft (no arg) as first, but we need to test when first has arg
        // Actually, items:list is better for this. But let's also test submit's second option path
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'submit');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud submit', $result);
        $this->assertStringContainsString('stud su', $result);
        $this->assertStringContainsString('--draft or -d', $result);
        $this->assertStringContainsString('--labels <labels>', $result);
    }

    public function testFormatCommandHelpFromTranslationWithItemsCreateSecondOptionWithType(): void
    {
        // items:create has second option --type <type>; exercises second-option argument branch
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'items:create');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud items:create', $result);
        $this->assertStringContainsString('stud ic', $result);
        $this->assertStringContainsString('Story', $result);
    }

    public function testFormatCommandHelpFromTranslationWithKeyArgument(): void
    {
        // Test formatCommandHelpFromTranslation directly with items:show
        // items:show has <key> as argument
        // This covers line 279: key argument type
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'items:show');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud items:show <key>', $result);
        $this->assertStringContainsString('(Alias: stud sh <key>)', $result);
        $this->assertStringContainsString('stud items:show JIRA-33', $result);
    }

    public function testFormatCommandHelpFromTranslationWithPrComment(): void
    {
        // Test formatCommandHelpFromTranslation directly with pr:comment
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'pr:comment');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud pr:comment <message>', $result);
        $this->assertStringContainsString('stud pc', $result);
        $this->assertStringContainsString('--reply-to <target>', $result);
        $this->assertStringContainsString('"Comment text"', $result);
    }

    public function testFormatCommandHelpFromTranslationWithFiltersShow(): void
    {
        // Test formatCommandHelpFromTranslation directly with filters:show
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'filters:show');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud filters:show <filterName>', $result);
        $this->assertStringContainsString('stud fs', $result);
        $this->assertStringContainsString('"My Filter"', $result);
    }

    public function testGetCommandHelpForFiltersShowHandlesConciseReadme(): void
    {
        $helpText = $this->helpService->getCommandHelp('filters:show');

        $this->assertNull($helpText);
    }

    public function testFormatCommandHelpFromTranslationWithItemsTransition(): void
    {
        // Test items:transition command with optional [<key>] argument
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'branch:rename');

        // Test intent: should return formatted help text with alias rn and --name option
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud branch:rename', $result);
        $this->assertStringContainsString('stud rn', $result);
        $this->assertStringContainsString('--name', $result);
        $this->assertStringContainsString('-N', $result);
        // Optional arguments should appear in signature but not in examples
        $this->assertStringContainsString('[<branch>]', $result);
        $this->assertStringContainsString('[<key>]', $result);
    }

    public function testFormatCommandHelpFromTranslationWithSwitch(): void
    {
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'switch');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud switch', $result);
        $this->assertStringContainsString('stud sw', $result);
        $this->assertStringContainsString('--sync', $result);
        $this->assertStringContainsString('-s', $result);
        $this->assertStringContainsString('<key>', $result);
    }

    public function testFormatCommandHelpFromTranslationWithItemsListSortOption(): void
    {
        // Test items:list command with --sort option (third option with <value> argument)
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'branch:rename');

        // Test intent: should include --name option with <name> argument in examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--name', $result);
        $this->assertStringContainsString('-N', $result);
        $this->assertStringContainsString('<name>', $result);
        // Verify the option appears in usage examples
        $this->assertStringContainsString('custom-branch-name', $result);
    }

    public function testFormatCommandHelpFromTranslationWithItemsListValueOption(): void
    {
        // Test items:list which has --sort as third option with <value> argument
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $result = $method->invoke($this->helpService, 'submit');

        // Test intent: should include --labels as second option with <labels> argument in usage examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify second option appears in usage examples
        $this->assertStringContainsString('--labels', $result);
        $this->assertStringContainsString('"bug,enhancement"', $result);
        $this->assertStringContainsString('--assign-to-author', $result);
    }

    public function testFormatCommandHelpFromTranslationWithCommitSecondOptionMessage(): void
    {
        // Test commit which has --message as second option with <message> argument
        // This covers the second option with <message> branch
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
        \App\Util\ReflectionAccessor::ensureAccessible($method);

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
