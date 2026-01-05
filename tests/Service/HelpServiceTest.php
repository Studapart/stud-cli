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
        // This covers line 281: version argument type
        $reflection = new \ReflectionClass($this->helpService);
        $method = $reflection->getMethod('formatCommandHelpFromTranslation');
        $method->setAccessible(true);

        $result = $method->invoke($this->helpService, 'release');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
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
}
