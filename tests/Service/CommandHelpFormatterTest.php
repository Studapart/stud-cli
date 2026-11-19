<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CommandHelpFormatter;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class CommandHelpFormatterTest extends TestCase
{
    private CommandHelpFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $translationService = $this->createStub(TranslationService::class);
        $translationService->method('trans')
            ->willReturnCallback(function ($id, $parameters = []) {
                return $id . (empty($parameters) ? '' : ' ' . json_encode($parameters));
            });

        $this->formatter = new CommandHelpFormatter($translationService);
    }

    public function testFormatReturnsNotFoundMessageForUnknownCommand(): void
    {
        $this->assertSame('help.command_not_found {"command":"nonexistent"}', $this->formatter->format('nonexistent'));
    }

    public function testFormatWithVersionArgument(): void
    {
        // Test format directly with release command
        // This covers the release command definition (lines 292-301) and version argument handling
        $result = $this->formatter->format('release');

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

    public function testFormatWithItemsListFirstOptionWithArgument(): void
    {
        // Test format directly with items:list
        // items:list has --project as first option with <key> argument
        // This covers lines 306-312: first option with argument handling
        $result = $this->formatter->format('items:list');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud items:list', $result);
        $this->assertStringContainsString('stud ls', $result);
        $this->assertStringContainsString('--project <key> or -p <key>', $result);
    }

    public function testFormatWithSubmitFirstOptionWithArgument(): void
    {
        // Test format directly with submit
        // submit has --draft (no arg) as first, but we need to test when first has arg
        // Actually, items:list is better for this. But let's also test submit's second option path
        $result = $this->formatter->format('submit');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud submit', $result);
        $this->assertStringContainsString('stud su', $result);
        $this->assertStringContainsString('--draft or -d', $result);
        $this->assertStringContainsString('--labels <labels>', $result);
    }

    public function testFormatWithItemsCreateSecondOptionWithType(): void
    {
        // items:create has second option --type <type>; exercises second-option argument branch
        $result = $this->formatter->format('items:create');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud items:create', $result);
        $this->assertStringContainsString('stud ic', $result);
        $this->assertStringContainsString('Story', $result);
    }

    public function testFormatWithKeyArgument(): void
    {
        // Test format directly with items:show
        // items:show has <key> as argument
        // This covers line 279: key argument type
        $result = $this->formatter->format('items:show');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud items:show <key>', $result);
        $this->assertStringContainsString('(Alias: stud sh <key>)', $result);
        $this->assertStringContainsString('stud items:show JIRA-33', $result);
    }

    public function testFormatWithPrComment(): void
    {
        // Test format directly with pr:comment
        $result = $this->formatter->format('pr:comment');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud pr:comment <message>', $result);
        $this->assertStringContainsString('stud pc', $result);
        $this->assertStringContainsString('--reply-to <target>', $result);
        $this->assertStringContainsString('"Comment text"', $result);
    }

    public function testFormatWithFiltersShow(): void
    {
        // Test format directly with filters:show
        $result = $this->formatter->format('filters:show');

        // Test intent: should return formatted help text
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud filters:show <filterName>', $result);
        $this->assertStringContainsString('stud fs', $result);
        $this->assertStringContainsString('"My Filter"', $result);
    }

    public function testFormatWithItemsTransition(): void
    {
        // Test items:transition command with optional [<key>] argument
        $result = $this->formatter->format('items:transition');

        // Test intent: should return formatted help text with alias tx
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud items:transition', $result);
        $this->assertStringContainsString('stud tx', $result);
        // Optional argument should appear in signature but not in examples
        $this->assertStringContainsString('[<key>]', $result);
    }

    public function testFormatWithBranchRename(): void
    {
        // Test branch:rename command with --name option and optional arguments
        $result = $this->formatter->format('branch:rename');

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

    public function testFormatWithSwitch(): void
    {
        $result = $this->formatter->format('switch');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud switch', $result);
        $this->assertStringContainsString('stud sw', $result);
        $this->assertStringContainsString('--sync', $result);
        $this->assertStringContainsString('-s', $result);
        $this->assertStringContainsString('<key>', $result);
    }

    public function testFormatWithItemsListSortOption(): void
    {
        // Test items:list command with --sort option (third option with <value> argument)
        $result = $this->formatter->format('items:list');

        // Test intent: should return formatted help text with --sort option
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--sort', $result);
        $this->assertStringContainsString('-s', $result);
        // Test that first option with argument is covered (--project with <key>)
        $this->assertStringContainsString('--project', $result);
        $this->assertStringContainsString('<key>', $result);
    }

    public function testFormatWithFirstOptionArgument(): void
    {
        // Test items:list which has --project as first option with <key> argument
        // This covers the code path marked as untestable but is actually testable
        $result = $this->formatter->format('items:list');

        // Test intent: should include --project option with argument in examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify the option with argument appears in usage examples
        $this->assertStringContainsString('--project', $result);
        $this->assertStringContainsString('-p', $result);
    }

    public function testFormatWithSecondOptionArgument(): void
    {
        // Test submit command which has --labels as second option with <labels> argument
        $result = $this->formatter->format('submit');

        // Test intent: should include --labels option with argument in examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--labels', $result);
        $this->assertStringContainsString('<labels>', $result);
    }

    public function testFormatWithBranchRenameNameOption(): void
    {
        // Test branch:rename which has --name as first option with <name> argument
        $result = $this->formatter->format('branch:rename');

        // Test intent: should include --name option with <name> argument in examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--name', $result);
        $this->assertStringContainsString('-N', $result);
        $this->assertStringContainsString('<name>', $result);
        // Verify the option appears in usage examples
        $this->assertStringContainsString('custom-branch-name', $result);
    }

    public function testFormatWithItemsListValueOption(): void
    {
        // Test items:list which has --sort as third option with <value> argument
        $result = $this->formatter->format('items:list');

        // Test intent: should include --sort option with <value> argument in options list
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--sort', $result);
        $this->assertStringContainsString('-s', $result);
        $this->assertStringContainsString('<value>', $result);
        // Note: --sort is third option, so it won't appear in usage examples (only first 2 options shown)
    }

    public function testFormatWithItemsListSecondOptionKey(): void
    {
        // Test items:list which has --project as second option with <key> argument
        // This covers the second option with <key> branch
        $result = $this->formatter->format('items:list');

        // Test intent: should include --project as second option with <key> argument in usage examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify second option appears in usage examples
        $this->assertStringContainsString('-p PROJ', $result);
        $this->assertStringContainsString('--project', $result);
    }

    public function testFormatWithSubmitSecondOptionLabels(): void
    {
        // Test submit which has --labels as second option with <labels> argument
        // This covers the second option with <labels> branch
        $result = $this->formatter->format('submit');

        // Test intent: should include --labels as second option with <labels> argument in usage examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify second option appears in usage examples
        $this->assertStringContainsString('--labels', $result);
        $this->assertStringContainsString('"bug,enhancement"', $result);
        $this->assertStringContainsString('--assign-to-author', $result);
    }

    public function testFormatWithCommitSecondOptionMessage(): void
    {
        // Test commit which has --message as second option with <message> argument
        // This covers the second option with <message> branch
        $result = $this->formatter->format('commit');

        // Test intent: should include --message as second option with <message> argument in usage examples
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Verify second option appears in usage examples
        $this->assertStringContainsString('--message', $result);
        $this->assertStringContainsString('-m', $result);
        $this->assertStringContainsString('"feat: My custom message"', $result);
    }

    public function testFormatWithNoOptions(): void
    {
        // Test a command with no options (e.g., status, please, flatten)
        // This covers the path where !empty($command['options']) is false
        $result = $this->formatter->format('status');

        // Test intent: should return formatted help text without options section
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud status', $result);
        $this->assertStringContainsString('stud ss', $result);
        // Should not contain options section
        $this->assertStringNotContainsString('-   Options:', $result);
    }

    public function testFormatWithNoAlias(): void
    {
        // Test completion command which has no alias
        // This covers the path where $command['alias'] is null
        $result = $this->formatter->format('completion');

        // Test intent: should return formatted help text without alias
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud completion', $result);
        // Should not contain alias
        $this->assertStringNotContainsString('(Alias:', $result);
    }

    public function testFormatWithSingleOption(): void
    {
        // Test branch:rename or update which have exactly one option
        // This covers the path where count($command['options']) is exactly 1
        // The second option block (count > 1) should not execute
        $result = $this->formatter->format('branch:rename');

        // Test intent: should return formatted help text with one option
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('--name', $result);
        // Should only show first option in examples, not second (since there is no second)
    }

    public function testFormatWithNoArguments(): void
    {
        // Test a command with no arguments (e.g., status, please)
        // This covers paths where empty($command['arguments']) is true
        $result = $this->formatter->format('please');

        // Test intent: should return formatted help text without arguments
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud please', $result);
        // Should not have argument examples in usage
    }

    public function testFormatWithAliasButNoArguments(): void
    {
        // Test a command with alias but no arguments (e.g., please, status)
        // This covers the path at line 301 where alias exists but arguments are empty
        $result = $this->formatter->format('status');

        // Test intent: should return formatted help text with alias but no arguments in alias
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('stud status', $result);
        $this->assertStringContainsString('stud ss', $result);
        $this->assertStringContainsString('(Alias: stud ss)', $result);
        // Alias should not have arguments appended
        $this->assertStringNotContainsString('(Alias: stud ss ', $result);
    }
}
