<?php

namespace App\Tests\Handler;

use App\DTO\WorkItem;
use App\Handler\ItemShowHandler;
use App\Service\JiraService;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\TableSeparator;
use RuntimeException;

class ItemShowHandlerTest extends CommandTestCase
{
    private ItemShowHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$jiraService = $this->jiraService;
        TestKernel::$translationService = $this->translationService;
        $this->handler = new ItemShowHandler($this->jiraService, [
            'JIRA_URL' => 'https://your-company.atlassian.net',
        ], $this->translationService);
    }

    public function testHandle(): void
    {
        $expectedDescription = "This is a test description.";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Create PHPUnit Test Suite for stud-cli Command Logic',
            'To Do',
            'Pierre-Emmanuel MANTEAU',
            $expectedDescription, // 6th argument: description (string)
            ['tests'], // 7th argument: labels (array)
            'Task', // 8th argument: issueType (string)
            [], // 9th argument: components (array)
            '<p>This is a test description.</p>' // 10th argument: renderedDescription (?string)
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true) // Expect true for renderFields
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('definitionList')
            ->with(
                ['Key' => 'TPW-35'],
                ['Title' => 'Create PHPUnit Test Suite for stud-cli Command Logic'],
                ['Status' => 'To Do'],
                ['Assignee' => 'Pierre-Emmanuel MANTEAU'],
                ['Type' => 'Task'],
                ['Labels' => 'tests'],
                new TableSeparator(),
                ['Link' => 'https://your-company.atlassian.net/browse/TPW-35']
            );
        $io->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && !empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($content) use ($expectedDescription) {
                return is_array($content) && in_array($expectedDescription, $content);
            }));

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithIssueNotFound(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true) // Expect true for renderFields
            ->willThrowException(new RuntimeException('Issue not found'));

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('error')
            ->with('Could not find Jira issue with key "TPW-35".');

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithVerboseOutput(): void
    {
        $expectedDescription = "My awesome feature description.";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            $expectedDescription, // 6th argument: description (string)
            ['tests'], // 7th argument: labels (array)
            'Task', // 8th argument: issueType (string)
            [], // 9th argument: components (array)
            '<p>My awesome feature description.</p>' // 10th argument: renderedDescription (?string)
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true) // Expect true for renderFields
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(true);
        $io->expects($this->once())
            ->method('writeln')
            ->with('  <fg=gray>Fetching details for issue: TPW-35</>');
        $io->expects($this->once())
            ->method('definitionList'); // We don't care about the content here, just that it's called
        $io->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && !empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($content) use ($expectedDescription) {
                return is_array($content) && in_array($expectedDescription, $content);
            }));

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithNoLabels(): void
    {
        $expectedDescription = "Description for no labels.";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            $expectedDescription, // 6th argument: description (string)
            [], // 7th argument: labels (array)
            'Task', // 8th argument: issueType (string)
            [], // 9th argument: components (array)
            '<p>Description for no labels.</p>' // 10th argument: renderedDescription (?string)
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true) // Expect true for renderFields
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('definitionList')
            ->with(
                ['Key' => 'TPW-35'],
                ['Title' => 'My awesome feature'],
                ['Status' => 'In Progress'],
                ['Assignee' => 'John Doe'],
                ['Type' => 'Task'],
                ['Labels' => 'None'],
                new TableSeparator(),
                ['Link' => 'https://your-company.atlassian.net/browse/TPW-35']
            );
        $io->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && !empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($content) use ($expectedDescription) {
                return is_array($content) && in_array($expectedDescription, $content);
            }));

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithMultipleLabels(): void
    {
        $expectedDescription = "Description for multiple labels.";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'My awesome feature',
            'In Progress',
            'John Doe',
            $expectedDescription, // 6th argument: description (string)
            ['label1', 'label2', 'label3'], // 7th argument: labels (array)
            'Task', // 8th argument: issueType (string)
            [], // 9th argument: components (array)
            '<p>Description for multiple labels.</p>' // 10th argument: renderedDescription (?string)
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true) // Expect true for renderFields
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('definitionList')
            ->with(
                ['Key' => 'TPW-35'],
                ['Title' => 'My awesome feature'],
                ['Status' => 'In Progress'],
                ['Assignee' => 'John Doe'],
                ['Type' => 'Task'],
                ['Labels' => 'label1, label2, label3'],
                new TableSeparator(),
                ['Link' => 'https://your-company.atlassian.net/browse/TPW-35']
            );
        $io->expects($this->atLeastOnce())
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && !empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($content) use ($expectedDescription) {
                return is_array($content) && in_array($expectedDescription, $content);
            }));

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithDescriptionContainingDividers(): void
    {
        $description = "Title: Test Feature\n\n---\n\nUser Story\nAs a developer\nI want to test\nSo that I can verify";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test Feature',
            'In Progress',
            'John Doe',
            $description,
            [],
            'Task',
            [],
            '<p>Test Feature</p>'
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true)
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('definitionList');
        // Should create multiple sections when dividers are present
        $io->expects($this->atLeast(2))
            ->method('section')
            ->with($this->callback(function ($title) {
                return is_string($title) && !empty($title);
            }));
        $io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content);
            }));

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithDescriptionContainingMultipleNewlines(): void
    {
        $description = "First paragraph\n\n\n\n\nSecond paragraph\n\n\nThird paragraph";
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test Feature',
            'In Progress',
            'John Doe',
            $description,
            [],
            'Task',
            [],
            '<p>Test Feature</p>'
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true)
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('definitionList');
        $io->expects($this->atLeastOnce())
            ->method('section');
        $io->expects($this->atLeastOnce())
            ->method('text')
            ->with($this->callback(function ($content) {
                // Verify that content is an array (multiple newlines are handled by sanitizeContent)
                return is_array($content);
            }));

        $this->handler->handle($io, 'TPW-35');
    }

    public function testHandleWithEmptyDescription(): void
    {
        $issue = new WorkItem(
            '1000',
            'TPW-35',
            'Test Feature',
            'In Progress',
            'John Doe',
            '', // Empty description
            [],
            'Task',
            [],
            null
        );

        $this->jiraService->expects($this->once())
            ->method('getIssue')
            ->with('TPW-35', true)
            ->willReturn($issue);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())
            ->method('section')
            ->with('Details for issue TPW-35'); // Main section header
        $io->expects($this->once())
            ->method('definitionList');
        // Should not call text for empty description (section is only called once for main header)
        $io->expects($this->never())
            ->method('text');

        $this->handler->handle($io, 'TPW-35');
    }

    public function testSanitizeContentWithConsecutiveEmptyLines(): void
    {
        $lines = ['Line 1', '', '', 'Line 2', '', '', '', 'Line 3'];
        $result = $this->callPrivateMethod($this->handler, 'sanitizeContent', [$lines]);
        
        $this->assertSame(['Line 1', '', 'Line 2', '', 'Line 3'], $result);
    }

    public function testSanitizeContentWithNoConsecutiveEmptyLines(): void
    {
        $lines = ['Line 1', 'Line 2', 'Line 3'];
        $result = $this->callPrivateMethod($this->handler, 'sanitizeContent', [$lines]);
        
        $this->assertSame($lines, $result);
    }

    public function testSanitizeContentWithSingleEmptyLine(): void
    {
        $lines = ['Line 1', '', 'Line 2'];
        $result = $this->callPrivateMethod($this->handler, 'sanitizeContent', [$lines]);
        
        $this->assertSame($lines, $result);
    }

    public function testParseDescriptionSectionsWithNoDividers(): void
    {
        $description = "Title: Test\nContent line 1\nContent line 2";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('title', $result[0]);
        $this->assertArrayHasKey('contentLines', $result[0]);
    }

    public function testParseDescriptionSectionsWithDividers(): void
    {
        $description = "Section 1\n---\nSection 2\n---\nSection 3";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    public function testParseDescriptionSectionsWithEmptyDescription(): void
    {
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', ['']);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseDescriptionSectionsWithWhitespaceOnly(): void
    {
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', ['   ']);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseDescriptionSectionsWithSingleLineNotHeader(): void
    {
        $description = "Just a regular line of text";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Should use default title and put the line as content
        $this->assertNotEmpty($result[0]['contentLines']);
    }

    public function testParseDescriptionSectionsWithSectionHeaderPattern(): void
    {
        $description = "Title: This is a title\nContent here";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Should recognize "Title:" pattern as a header
        $this->assertStringContainsString('Title', $result[0]['title']);
    }

    public function testDisplayContentWithCheckboxes(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['[ ] Checkbox 1', '[x] Checkbox 2', '[ ] Checkbox 3'];
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 3;
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithCheckboxesAndSubItems(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['[ ] Main item', 'Sub-item 1', 'Sub-item 2', '[x] Another item'];
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 2;
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithMixedContent(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['Regular text', '[ ] Checkbox item', 'More text'];
        
        $io->expects($this->once())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content);
            }));
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list);
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithEmptyLines(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['Line 1', '', 'Line 2'];
        
        $io->expects($this->once())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content) && in_array('Line 1', $content) && in_array('Line 2', $content);
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithOnlyText(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['Text line 1', 'Text line 2', 'Text line 3'];
        
        $io->expects($this->once())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content) && count($content) === 3;
            }));
        
        $io->expects($this->never())
            ->method('listing');
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithCheckboxAtEnd(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['Text before', '[ ] Final checkbox'];
        
        $io->expects($this->once())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content) && in_array('Text before', $content);
            }));
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 1;
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithCheckedCheckbox(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['[X] Checked item', '[x] Also checked'];
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 2;
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayDescriptionWithEmptyString(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        
        $io->expects($this->never())
            ->method('section');
        
        $io->expects($this->never())
            ->method('text');
        
        $this->callPrivateMethod($this->handler, 'displayDescription', [$io, '']);
    }

    public function testDisplayDescriptionWithWhitespaceOnly(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        
        $io->expects($this->never())
            ->method('section');
        
        $io->expects($this->never())
            ->method('text');
        
        $this->callPrivateMethod($this->handler, 'displayDescription', [$io, '   ']);
    }
}
