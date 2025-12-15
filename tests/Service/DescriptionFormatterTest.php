<?php

namespace App\Tests\Service;

use App\Service\DescriptionFormatter;
use App\Tests\CommandTestCase;

class DescriptionFormatterTest extends CommandTestCase
{
    private DescriptionFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new DescriptionFormatter($this->translationService);
    }

    public function testSanitizeContentWithConsecutiveEmptyLines(): void
    {
        $lines = ['Line 1', '', '', 'Line 2', '', '', '', 'Line 3'];
        $result = $this->callPrivateMethod($this->formatter, 'sanitizeContent', [$lines]);

        $this->assertSame(['Line 1', '', 'Line 2', '', 'Line 3'], $result);
    }

    public function testSanitizeContentWithNoConsecutiveEmptyLines(): void
    {
        $lines = ['Line 1', 'Line 2', 'Line 3'];
        $result = $this->callPrivateMethod($this->formatter, 'sanitizeContent', [$lines]);

        $this->assertSame($lines, $result);
    }

    public function testSanitizeContentWithSingleEmptyLine(): void
    {
        $lines = ['Line 1', '', 'Line 2'];
        $result = $this->callPrivateMethod($this->formatter, 'sanitizeContent', [$lines]);

        $this->assertSame($lines, $result);
    }

    public function testParseSectionsWithNoDividers(): void
    {
        $description = "Title: Test\nContent line 1\nContent line 2";
        $result = $this->formatter->parseSections($description);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('title', $result[0]);
        $this->assertArrayHasKey('contentLines', $result[0]);
    }

    public function testParseSectionsWithDividers(): void
    {
        $description = "Section 1\n---\nSection 2\n---\nSection 3";
        $result = $this->formatter->parseSections($description);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    public function testParseSectionsWithEmptyDescription(): void
    {
        $result = $this->formatter->parseSections('');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseSectionsWithWhitespaceOnly(): void
    {
        $result = $this->formatter->parseSections('   ');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseSectionsWithSingleLineNotHeader(): void
    {
        $description = "Just a regular line of text";
        $result = $this->formatter->parseSections($description);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertNotEmpty($result[0]['contentLines']);
    }

    public function testParseSectionsWithSectionHeaderPattern(): void
    {
        $description = "Title: This is a title\nContent here";
        $result = $this->formatter->parseSections($description);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('Title', $result[0]['title']);
    }

    public function testParseSectionsWithEmptyLineAfterTitle(): void
    {
        $description = "Title\n\nContent line";
        $result = $this->formatter->parseSections($description);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertNotEmpty($result[0]['contentLines']);
        $this->assertContains('', $result[0]['contentLines']);
    }

    public function testParseSectionsWithDescriptionContainingDividers(): void
    {
        $description = "Title: Test Feature\n\n---\n\nUser Story\nAs a developer";
        $result = $this->formatter->parseSections($description);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testParseSectionsWithMultipleNewlines(): void
    {
        $description = "First paragraph\n\n\n\n\nSecond paragraph\n\n\nThird paragraph";
        $result = $this->formatter->parseSections($description);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testParseSectionsWithUserStoryHeader(): void
    {
        $description = "User Story";
        $result = $this->formatter->parseSections($description);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('User Story', $result[0]['title']);
    }

    public function testParseSectionsWithAcceptanceCriteriaHeader(): void
    {
        $description = "Acceptance Criteria:";
        $result = $this->formatter->parseSections($description);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertStringContainsString('Acceptance Criteria', $result[0]['title']);
    }

    public function testFormatContentForDisplayWithCheckboxes(): void
    {
        $lines = ['[ ] Checkbox 1', '[x] Checkbox 2', '[ ] Checkbox 3'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lists', $result);
        $this->assertArrayHasKey('text', $result);
        $this->assertCount(1, $result['lists']);
        $this->assertCount(3, $result['lists'][0]);
    }

    public function testFormatContentForDisplayWithCheckboxesAndSubItems(): void
    {
        $lines = ['[ ] Main item', 'Sub-item 1', 'Sub-item 2', '[x] Another item'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['lists']);
        $this->assertCount(2, $result['lists'][0]);
        $this->assertStringContainsString('Sub-item', $result['lists'][0][0]);
    }

    public function testFormatContentForDisplayWithMixedContent(): void
    {
        $lines = ['Regular text', '[ ] Checkbox item', 'More text'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['text']);
        $this->assertNotEmpty($result['lists']);
    }

    public function testFormatContentForDisplayWithEmptyLines(): void
    {
        $lines = ['Line 1', '', 'Line 2'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['text']);
    }

    public function testFormatContentForDisplayWithOnlyText(): void
    {
        $lines = ['Text line 1', 'Text line 2', 'Text line 3'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertEmpty($result['lists']);
        $this->assertNotEmpty($result['text']);
        $this->assertCount(3, $result['text'][0]);
    }

    public function testFormatContentForDisplayWithCheckboxAtEnd(): void
    {
        $lines = ['Text before', '[ ] Final checkbox'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['text']);
        $this->assertNotEmpty($result['lists']);
    }

    public function testFormatContentForDisplayWithCheckedCheckbox(): void
    {
        $lines = ['[X] Checked item', '[x] Also checked'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['lists']);
        $this->assertCount(2, $result['lists'][0]);
    }

    public function testFormatContentForDisplayWithEmptySubItem(): void
    {
        $lines = ['[ ] Main item', '', 'Sub-item'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['lists']);
        $this->assertCount(1, $result['lists'][0]);
    }

    public function testFormatContentForDisplayWithLastListItemHavingSubItems(): void
    {
        $lines = ['[ ] Item', 'Sub-item'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['lists']);
        $this->assertCount(1, $result['lists'][0]);
        $this->assertStringContainsString('Sub-item', $result['lists'][0][0]);
    }

    public function testFormatContentForDisplayWithLastListItemNoSubItems(): void
    {
        $lines = ['[ ] Item'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['lists']);
        $this->assertCount(1, $result['lists'][0]);
    }

    public function testFormatContentForDisplayWithMultipleCheckboxesAndSubItems(): void
    {
        $lines = ['[ ] Item 1', 'Sub 1', 'Sub 2', '[ ] Item 2', 'Sub 3'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['lists']);
        $this->assertCount(2, $result['lists'][0]);
    }

    public function testFormatContentForDisplayWithTextThenCheckboxThenText(): void
    {
        $lines = ['Text before', '[ ] Checkbox', 'Text after'];
        $result = $this->formatter->formatContentForDisplay($lines);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['text']);
        $this->assertNotEmpty($result['lists']);
    }
}
