<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CommentBodyParser;
use PHPUnit\Framework\TestCase;

class CommentBodyParserTest extends TestCase
{
    private CommentBodyParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CommentBodyParser();
    }

    public function testParseReturnsTextSegmentForPlainParagraph(): void
    {
        $segments = $this->parser->parse('Hello world.');
        $this->assertCount(1, $segments);
        $this->assertSame('text', $segments[0]['type']);
        $this->assertSame('Hello world.', $segments[0]['content']);
    }

    public function testParseTrimsLines(): void
    {
        $segments = $this->parser->parse("  Line one  \n  Line two  ");
        $this->assertCount(1, $segments);
        $this->assertSame("Line one\nLine two", $segments[0]['content']);
    }

    public function testParseReturnsListSegmentForMarkdownList(): void
    {
        $segments = $this->parser->parse("- one\n- two\n- three");
        $this->assertCount(1, $segments);
        $this->assertSame('list', $segments[0]['type']);
        $this->assertSame(['one', 'two', 'three'], $segments[0]['items']);
    }

    public function testParseRecognizesAsteriskList(): void
    {
        $segments = $this->parser->parse("* one\n* two");
        $this->assertCount(1, $segments);
        $this->assertSame('list', $segments[0]['type']);
        $this->assertSame(['one', 'two'], $segments[0]['items']);
    }

    public function testParseRecognizesOrderedList(): void
    {
        $segments = $this->parser->parse("1. first\n2. second");
        $this->assertCount(1, $segments);
        $this->assertSame('list', $segments[0]['type']);
        $this->assertSame(['first', 'second'], $segments[0]['items']);
    }

    public function testParseIsListItemReturnsFalseForPlainText(): void
    {
        $segments = $this->parser->parse("Not a list item");
        $this->assertCount(1, $segments);
        $this->assertSame('text', $segments[0]['type']);
        $this->assertSame('Not a list item', $segments[0]['content']);
    }

    public function testParseReturnsTableSegmentForMarkdownTable(): void
    {
        $body = "| A | B |\n| --- | --- |\n| 1 | 2 |";
        $segments = $this->parser->parse($body);
        $this->assertCount(1, $segments);
        $this->assertSame('table', $segments[0]['type']);
        $this->assertSame(['A', 'B'], $segments[0]['headers']);
        $this->assertSame([['1', '2']], $segments[0]['rows']);
    }

    public function testParseReturnsMixedSegments(): void
    {
        $body = "Intro.\n\n| X | Y |\n| --- | --- |\n| a | b |\n\nAfter.";
        $segments = $this->parser->parse($body);
        $this->assertCount(3, $segments);
        $this->assertSame('text', $segments[0]['type']);
        $this->assertSame('Intro.', $segments[0]['content']);
        $this->assertSame('table', $segments[1]['type']);
        $this->assertSame(['X', 'Y'], $segments[1]['headers']);
        $this->assertSame([['a', 'b']], $segments[1]['rows']);
        $this->assertSame('text', $segments[2]['type']);
        $this->assertSame('After.', $segments[2]['content']);
    }

    public function testParseReturnsEmptyContentForEmptyBody(): void
    {
        $segments = $this->parser->parse('');
        $this->assertCount(0, $segments);
    }

    public function testParseNormalizesLineEndings(): void
    {
        $segments = $this->parser->parse("Line one\r\nLine two\rLine three");
        $this->assertCount(1, $segments);
        $this->assertSame("Line one\nLine two\nLine three", $segments[0]['content']);
    }

    public function testParseTableWithSingleRowNoSeparator(): void
    {
        $body = "| Only |";
        $segments = $this->parser->parse($body);
        $this->assertCount(1, $segments);
        $this->assertSame('table', $segments[0]['type']);
        $this->assertSame([], $segments[0]['headers']);
        $this->assertSame([['Only']], $segments[0]['rows']);
    }

    public function testParseTableWithEmptyCellRowIncluded(): void
    {
        $body = "| A | B |\n||\n| 1 | 2 |";
        $segments = $this->parser->parse($body);
        $this->assertCount(1, $segments);
        $this->assertSame('table', $segments[0]['type']);
        $this->assertSame(['A', 'B'], $segments[0]['headers']);
        $this->assertSame([[], ['1', '2']], $segments[0]['rows']);
    }
}
