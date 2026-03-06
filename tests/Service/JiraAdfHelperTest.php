<?php

namespace App\Tests\Service;

use App\Service\JiraAdfHelper;
use PHPUnit\Framework\TestCase;

class JiraAdfHelperTest extends TestCase
{
    public function testPlainTextToAdfSingleParagraph(): void
    {
        $adf = JiraAdfHelper::plainTextToAdf('Hello world');

        $this->assertSame('doc', $adf['type']);
        $this->assertSame(1, $adf['version']);
        $this->assertCount(1, $adf['content']);
        $this->assertSame('paragraph', $adf['content'][0]['type']);
        $this->assertSame('Hello world', $adf['content'][0]['content'][0]['text']);
    }

    public function testPlainTextToAdfMultipleParagraphs(): void
    {
        $text = "First para\n\nSecond para";
        $adf = JiraAdfHelper::plainTextToAdf($text);

        $this->assertSame('doc', $adf['type']);
        $this->assertCount(2, $adf['content']);
        $this->assertSame('First para', $adf['content'][0]['content'][0]['text']);
        $this->assertSame('Second para', $adf['content'][1]['content'][0]['text']);
    }

    public function testPlainTextToAdfEmptyString(): void
    {
        $adf = JiraAdfHelper::plainTextToAdf('');

        $this->assertSame('doc', $adf['type']);
        $this->assertSame(1, $adf['version']);
        $this->assertCount(1, $adf['content']);
        $this->assertSame('paragraph', $adf['content'][0]['type']);
        $this->assertSame([], $adf['content'][0]['content']);
    }

    public function testPlainTextToAdfSkipsEmptyParagraphs(): void
    {
        $text = "First\n\n\n\nSecond";
        $adf = JiraAdfHelper::plainTextToAdf($text);

        $this->assertSame('doc', $adf['type']);
        $this->assertCount(2, $adf['content']);
        $this->assertSame('First', $adf['content'][0]['content'][0]['text']);
        $this->assertSame('Second', $adf['content'][1]['content'][0]['text']);
    }
}
