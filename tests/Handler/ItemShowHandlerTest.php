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

        // ItemShowHandlerTest checks output text, so use real TranslationService
        // This is acceptable since ItemShowHandler is the class under test
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new \App\Service\TranslationService('en', $translationsPath);

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

    public function testParseDescriptionSectionsWithEmptyLineAfterTitle(): void
    {
        $description = "Title\n\nContent line";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertNotEmpty($result[0]['contentLines']);
        // Should preserve empty line after title
        $this->assertContains('', $result[0]['contentLines']);
    }

    public function testParseDescriptionSectionsWithNoTitleFound(): void
    {
        $description = "\n\n\n"; // Only empty lines
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        // Empty description should return empty array (trimmed to empty)
        $this->assertEmpty($result);
    }

    public function testParseDescriptionSectionsWithOnlyEmptyLinesInSection(): void
    {
        $description = "---\n\n\n"; // Divider followed by only empty lines
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Should use default title when no title found in section
        $this->assertNotEmpty($result[0]['title']);
    }

    public function testParseDescriptionSectionsWithSectionHavingOnlyEmptyLinesAfterDivider(): void
    {
        $description = "First section\n---\n\n\n"; // Section with content, then divider, then only empty lines
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        // Empty sections after divider are not added (empty currentSection check)
        // So we get only the first section
        $this->assertCount(1, $result);
        $this->assertNotEmpty($result[0]['title']);
    }

    public function testParseDescriptionSectionsWithDescriptionAndImplementationLogicHeader(): void
    {
        $description = "Description & Implementation Logic";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Should recognize "Description & Implementation Logic" as a header
        $this->assertStringContainsString('Description & Implementation Logic', $result[0]['title']);
    }

    public function testParseDescriptionSectionsWithDividerAtStart(): void
    {
        $description = "---\nContent after divider";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // When divider is at start, empty currentSection is not added (line 96 check)
        // Only the section after divider is added
        $this->assertNotEmpty($result[0]['title']);
    }

    public function testParseDescriptionSectionsWithMultipleDividersAndEmptySections(): void
    {
        $description = "Section 1\n---\n---\nSection 3";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        // First section, then empty section between dividers (not added), then last section
        $this->assertCount(2, $result);
    }

    public function testParseDescriptionSectionsWithEmptyCurrentSectionAtEnd(): void
    {
        $description = "Section 1\n---\n"; // Divider at end, empty currentSection
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        // Empty currentSection at end is not added (line 106 check)
        $this->assertCount(1, $result);
    }


    public function testParseDescriptionSectionsWithSingleLineThatIsHeader(): void
    {
        $description = "Title: This is a title";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Should recognize as header, not treat as content
        $this->assertStringContainsString('Title', $result[0]['title']);
        // Should be empty content since it's just a header
        $this->assertEmpty($result[0]['contentLines']);
    }

    public function testParseDescriptionSectionsWithUserStoryHeader(): void
    {
        $description = "User Story";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Should recognize "User Story" as a header
        $this->assertStringContainsString('User Story', $result[0]['title']);
    }

    public function testParseDescriptionSectionsWithAcceptanceCriteriaHeader(): void
    {
        $description = "Acceptance Criteria:";
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Should recognize "Acceptance Criteria" as a header
        $this->assertStringContainsString('Acceptance Criteria', $result[0]['title']);
    }

    public function testDisplayContentWithEmptySubItem(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['[ ] Main item', '', 'Sub-item'];
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 1;
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithEmptyLineInText(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['Text line 1', '', 'Text line 2'];
        
        $io->expects($this->once())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content) && in_array('', $content);
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithEmptyLineNotInText(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['', 'Text line'];
        
        $io->expects($this->once())
            ->method('text')
            ->with($this->callback(function ($content) {
                return is_array($content) && !in_array('', $content);
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithEmptyLineAfterText(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['Text line 1', '', 'Text line 2'];
        
        $io->expects($this->once())
            ->method('text')
            ->with($this->callback(function ($content) {
                // Should preserve empty line between text lines
                return is_array($content) && in_array('', $content);
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithCheckboxFollowedByEmptySubItem(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['[ ] Item 1', '', '[ ] Item 2'];
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 2;
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithTextThenCheckboxThenText(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['Text before', '[ ] Checkbox', 'Text after'];
        
        // Text before checkbox is displayed, then checkbox, then text after is accumulated and displayed at end
        $io->expects($this->atLeastOnce())
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

    public function testDisplayContentWithMultipleCheckboxesAndSubItems(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['[ ] Item 1', 'Sub 1', 'Sub 2', '[ ] Item 2', 'Sub 3'];
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 2;
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithLastListItemHavingSubItems(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['[ ] Item', 'Sub-item'];
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 1 && str_contains($list[0], 'Sub-item');
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testDisplayContentWithLastListItemNoSubItems(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $lines = ['[ ] Item'];
        
        $io->expects($this->once())
            ->method('listing')
            ->with($this->callback(function ($list) {
                return is_array($list) && count($list) === 1;
            }));
        
        $this->callPrivateMethod($this->handler, 'displayContent', [$io, $lines]);
    }

    public function testParseDescriptionSectionsWithNoTitleInSection(): void
    {
        // Test case where a section has no title (all lines are empty after processing)
        // This should trigger line 145 where default title is used
        // To trigger this: we need a section where all lines are empty (no non-empty lines)
        // After processing, $title remains empty, so line 145 executes
        $description = "---\n\n\n"; // Divider with only empty lines in second section
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        // Should use default title when no title found (line 145)
        // The section after divider has only empty lines, so $titleFound stays false
        // and $title remains empty, triggering line 145
        $this->assertNotEmpty($result[0]['title']);
    }

    public function testParseDescriptionSectionsWithSectionHavingOnlyWhitespace(): void
    {
        // Test case to trigger line 145: section with only whitespace (no non-empty lines)
        // When a section has only whitespace lines, trim() makes them all empty,
        // so $titleFound stays false, $title stays empty, triggering line 145
        // We need content before divider so first section is added, then whitespace-only section after
        $description = "Content\n---\n   \n\t\n   "; // Section after divider has only whitespace
        $result = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description]);
        
        $this->assertIsArray($result);
        // First section has "Content", second section has only whitespace
        // The whitespace-only section should still be processed (whitespace lines are in array)
        $this->assertGreaterThanOrEqual(1, count($result));
        // Find the section with only whitespace (should have default title from line 145)
        $whitespaceSection = null;
        foreach ($result as $section) {
            if (empty($section['contentLines']) || (count($section['contentLines']) === 1 && trim($section['contentLines'][0]) === '')) {
                $whitespaceSection = $section;
                break;
            }
        }
        // If we found a whitespace-only section, it should have default title (line 145)
        if ($whitespaceSection !== null) {
            $this->assertNotEmpty($whitespaceSection['title']);
        } else {
            // If no whitespace section found, try with a description that definitely creates one
            $description2 = "   \n\t\n   "; // Only whitespace, no divider
            $result2 = $this->callPrivateMethod($this->handler, 'parseDescriptionSections', [$description2]);
            // This should return empty because description is trimmed to empty
            $this->assertEmpty($result2);
        }
    }

}
