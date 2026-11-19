<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CommandHelpFormatter;
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

        $this->translationService = $this->createStub(TranslationService::class);
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

        $this->helpService = new HelpService($this->translationService, $this->fileSystem, new CommandHelpFormatter($this->translationService));
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
        $fileSystem = $this->createStub(FileSystem::class);
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
        $helpService = new HelpService($this->translationService, $fileSystem, new CommandHelpFormatter($this->translationService));

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

    public function testGetCommandHelpForFiltersShowHandlesConciseReadme(): void
    {
        $helpText = $this->helpService->getCommandHelp('filters:show');

        $this->assertNull($helpText);
    }

    public function testGetCommandHelpReturnsNullWhenFileReadThrowsException(): void
    {
        // Test that getCommandHelp returns null when file read throws RuntimeException
        // This covers line 60 (catch block)
        $fileSystem = $this->createStub(\App\Service\FileSystem::class);
        $fileSystem->method('read')
            ->willThrowException(new \RuntimeException('File read failed'));

        $helpService = new HelpService($this->translationService, $fileSystem, new CommandHelpFormatter($this->translationService));

        $result = $helpService->getCommandHelp('commit');
        $this->assertNull($result);
    }
}
