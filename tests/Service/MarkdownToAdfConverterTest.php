<?php

namespace App\Tests\Service;

use App\Service\MarkdownToAdfConverter;
use PHPUnit\Framework\TestCase;

class MarkdownToAdfConverterTest extends TestCase
{
    private MarkdownToAdfConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new MarkdownToAdfConverter();
    }

    public function testConvertEmptyString(): void
    {
        $adf = $this->converter->convert('');

        $this->assertSame('doc', $adf['type']);
        $this->assertSame(1, $adf['version']);
        $this->assertArrayHasKey('content', $adf);
        $this->assertCount(1, $adf['content']);
        $this->assertSame('paragraph', $adf['content'][0]['type']);
    }

    public function testConvertHeadingAndParagraph(): void
    {
        $adf = $this->converter->convert("# Title\n\nBody text");

        $this->assertSame('doc', $adf['type']);
        $this->assertSame(1, $adf['version']);
        $this->assertCount(2, $adf['content']);
        $this->assertSame('heading', $adf['content'][0]['type']);
        $this->assertSame(1, $adf['content'][0]['attrs']['level']);
        $this->assertSame('Title', $adf['content'][0]['content'][0]['text']);
        $this->assertSame('paragraph', $adf['content'][1]['type']);
        $this->assertSame('Body text', $adf['content'][1]['content'][0]['text']);
    }

    public function testConvertBoldAndEmphasis(): void
    {
        $adf = $this->converter->convert('**bold** and *em*');

        $this->assertSame('doc', $adf['type']);
        $content = $adf['content'][0]['content'];
        $this->assertGreaterThanOrEqual(2, count($content));
        $boldNode = null;
        $emNode = null;
        foreach ($content as $node) {
            if (isset($node['marks'][0]['type'])) {
                if ($node['marks'][0]['type'] === 'strong') {
                    $boldNode = $node;
                }
                if ($node['marks'][0]['type'] === 'em') {
                    $emNode = $node;
                }
            }
        }
        $this->assertNotNull($boldNode);
        $this->assertSame('bold', $boldNode['text']);
        $this->assertNotNull($emNode);
        $this->assertSame('em', $emNode['text']);
    }

    public function testConvertInlineCode(): void
    {
        $adf = $this->converter->convert('Use `code` here');

        $this->assertSame('doc', $adf['type']);
        $content = $adf['content'][0]['content'];
        $this->assertGreaterThanOrEqual(1, count($content));
        $codeNode = null;
        foreach ($content as $node) {
            if (isset($node['marks']) && $node['text'] === 'code') {
                $codeNode = $node;

                break;
            }
        }
        $this->assertNotNull($codeNode);
        $this->assertSame('code', $codeNode['marks'][0]['type']);
    }

    public function testConvertBulletList(): void
    {
        $adf = $this->converter->convert("- one\n- two");

        $this->assertSame('doc', $adf['type']);
        $this->assertSame('bulletList', $adf['content'][0]['type']);
        $this->assertCount(2, $adf['content'][0]['content']);
        $this->assertSame('listItem', $adf['content'][0]['content'][0]['type']);
        $this->assertSame('one', $adf['content'][0]['content'][0]['content'][0]['content'][0]['text']);
    }

    public function testConvertFencedCodeBlock(): void
    {
        $adf = $this->converter->convert("```php\necho 'hi';\n```");

        $this->assertSame('doc', $adf['type']);
        $this->assertSame('codeBlock', $adf['content'][0]['type']);
        $this->assertSame('php', $adf['content'][0]['attrs']['language']);
        $this->assertStringContainsString('echo', $adf['content'][0]['content'][0]['text']);
    }

    public function testConvertIndentedCodeBlock(): void
    {
        $adf = $this->converter->convert("Paragraph\n\n    indented code line\n");

        $this->assertSame('doc', $adf['type']);
        $codeBlock = null;
        foreach ($adf['content'] as $node) {
            if (isset($node['type']) && $node['type'] === 'codeBlock') {
                $codeBlock = $node;

                break;
            }
        }
        $this->assertNotNull($codeBlock);
        $this->assertStringContainsString('indented code', $codeBlock['content'][0]['text'] ?? '');
    }

    public function testConvertThematicBreak(): void
    {
        $adf = $this->converter->convert("Above\n\n---\n\nBelow");

        $this->assertSame('doc', $adf['type']);
        $rule = null;
        foreach ($adf['content'] as $node) {
            if (isset($node['type']) && $node['type'] === 'rule') {
                $rule = $node;

                break;
            }
        }
        $this->assertNotNull($rule);
    }

    public function testConvertBlockquote(): void
    {
        $adf = $this->converter->convert("> Quoted line");

        $this->assertSame('doc', $adf['type']);
        $this->assertSame('blockquote', $adf['content'][0]['type']);
        $this->assertSame('Quoted line', $adf['content'][0]['content'][0]['content'][0]['text']);
    }

    public function testConvertOrderedList(): void
    {
        $adf = $this->converter->convert("1. first\n2. second");

        $this->assertSame('doc', $adf['type']);
        $this->assertSame('orderedList', $adf['content'][0]['type']);
        $this->assertCount(2, $adf['content'][0]['content']);
        $this->assertSame('first', $adf['content'][0]['content'][0]['content'][0]['content'][0]['text']);
    }

    public function testConvertLink(): void
    {
        $adf = $this->converter->convert('[Link text](https://example.com)');

        $this->assertSame('doc', $adf['type']);
        $content = $adf['content'][0]['content'];
        $linkNode = null;
        foreach ($content as $node) {
            if (isset($node['type']) && $node['type'] === 'text' && isset($node['marks'][0]['type']) && $node['marks'][0]['type'] === 'link') {
                $linkNode = $node;

                break;
            }
        }
        $this->assertNotNull($linkNode);
        $this->assertSame('Link text', $linkNode['text']);
    }

    public function testConvertNestedEmphasisInStrong(): void
    {
        $adf = $this->converter->convert('**bold *nested* more**');

        $this->assertSame('doc', $adf['type']);
        $this->assertNotEmpty($adf['content'][0]['content']);
    }

    public function testConvertImage(): void
    {
        $adf = $this->converter->convert('![alt text](https://example.com/img.png)');

        $this->assertSame('doc', $adf['type']);
        $this->assertNotEmpty($adf['content']);
    }
}
