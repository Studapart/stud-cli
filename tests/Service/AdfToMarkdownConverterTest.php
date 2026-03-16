<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AdfToMarkdownConverter;
use PHPUnit\Framework\TestCase;

class AdfToMarkdownConverterTest extends TestCase
{
    private AdfToMarkdownConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new AdfToMarkdownConverter();
    }

    public function testConvertEmptyDocReturnsEmpty(): void
    {
        $adf = ['type' => 'doc', 'content' => []];
        self::assertSame('', $this->converter->convert($adf));
    }

    public function testConvertParagraphWithText(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Hello world']],
                ],
            ],
        ];
        self::assertSame('Hello world', $this->converter->convert($adf));
    }

    public function testConvertHeading(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 1],
                    'content' => [['type' => 'text', 'text' => 'Title']],
                ],
            ],
        ];
        self::assertSame('# Title', $this->converter->convert($adf));
    }

    public function testConvertStrongAndEmMarks(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'strong']]],
                        ['type' => 'text', 'text' => ' and '],
                        ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'em']]],
                    ],
                ],
            ],
        ];
        self::assertSame('**bold** and *italic*', $this->converter->convert($adf));
    }

    public function testConvertBulletList(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'bulletList',
                    'content' => [
                        [
                            'type' => 'listItem',
                            'content' => [
                                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One']]],
                            ],
                        ],
                        [
                            'type' => 'listItem',
                            'content' => [
                                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Two']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame("- One\n- Two", $this->converter->convert($adf));
    }

    public function testConvertCodeBlock(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'codeBlock',
                    'content' => [['type' => 'text', 'text' => 'echo "hi";']],
                ],
            ],
        ];
        self::assertSame("```\necho \"hi\";\n```", $this->converter->convert($adf));
    }

    public function testConvertLinkMark(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'link',
                            'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame('[link](https://example.com)', $this->converter->convert($adf));
    }

    public function testConvertContentMissingReturnsEmpty(): void
    {
        self::assertSame('', $this->converter->convert(['type' => 'doc']));
    }
}
