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

    public function testConvertContentNotArrayReturnsEmpty(): void
    {
        self::assertSame('', $this->converter->convert(['type' => 'doc', 'content' => 'not-array']));
    }

    public function testConvertOrderedList(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'orderedList',
                    'content' => [
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First']]]]],
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second']]]]],
                    ],
                ],
            ],
        ];
        self::assertSame("1. First\n2. Second", $this->converter->convert($adf));
    }

    public function testConvertRule(): void
    {
        $adf = ['type' => 'doc', 'content' => [['type' => 'rule']]];
        self::assertSame('---', $this->converter->convert($adf));
    }

    public function testConvertBlockquote(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'blockquote',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quoted line']]],
                    ],
                ],
            ],
        ];
        self::assertSame('> Quoted line', $this->converter->convert($adf));
    }

    public function testConvertPanel(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'panel',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Panel text']]],
                    ],
                ],
            ],
        ];
        self::assertSame('Panel text', $this->converter->convert($adf));
    }

    public function testConvertTable(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'table',
                    'content' => [
                        [
                            'type' => 'tableRow',
                            'content' => [
                                ['type' => 'tableHeader', 'content' => [['type' => 'text', 'text' => 'A']]],
                                ['type' => 'tableHeader', 'content' => [['type' => 'text', 'text' => 'B']]],
                            ],
                        ],
                        [
                            'type' => 'tableRow',
                            'content' => [
                                ['type' => 'tableCell', 'content' => [['type' => 'text', 'text' => '1']]],
                                ['type' => 'tableCell', 'content' => [['type' => 'text', 'text' => '2']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $out = $this->converter->convert($adf);
        self::assertStringContainsString('| A | B |', $out);
        self::assertStringContainsString('| 1 | 2 |', $out);
        self::assertStringContainsString('---', $out);
    }

    public function testConvertUnknownBlockWithContent(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'extension',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nested']]],
                    ],
                ],
            ],
        ];
        self::assertSame('Nested', $this->converter->convert($adf));
    }

    public function testConvertUnknownBlockWithoutContent(): void
    {
        $adf = ['type' => 'doc', 'content' => [['type' => 'extension']]];
        self::assertSame('', $this->converter->convert($adf));
    }

    public function testConvertHardBreak(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Line1'],
                        ['type' => 'hardBreak'],
                        ['type' => 'text', 'text' => 'Line2'],
                    ],
                ],
            ],
        ];
        self::assertSame("Line1\nLine2", $this->converter->convert($adf));
    }

    public function testConvertMentionWithText(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'mention', 'attrs' => ['text' => '@Jane']],
                    ],
                ],
            ],
        ];
        self::assertSame('@Jane', $this->converter->convert($adf));
    }

    public function testConvertMentionWithoutText(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => []]]]],
        ];
        self::assertSame('', $this->converter->convert($adf));
    }

    public function testConvertStrikeMark(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'struck', 'marks' => [['type' => 'strike']]],
                    ],
                ],
            ],
        ];
        self::assertSame('~~struck~~', $this->converter->convert($adf));
    }

    public function testConvertUnderlineMark(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'underlined', 'marks' => [['type' => 'underline']]],
                    ],
                ],
            ],
        ];
        self::assertSame('underlined', $this->converter->convert($adf));
    }

    public function testConvertCodeMark(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'code', 'marks' => [['type' => 'code']]],
                    ],
                ],
            ],
        ];
        self::assertSame('`code`', $this->converter->convert($adf));
    }

    public function testConvertListItemWithNestedBlock(): void
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
                                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'P']]],
                                ['type' => 'bulletList', 'content' => [
                                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nested']]]]],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $out = $this->converter->convert($adf);
        self::assertStringContainsString('Nested', $out);
        self::assertStringContainsString('-', $out);
    }

    public function testConvertListItemsSkipsNonListItem(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'bulletList',
                    'content' => [
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A']]]]],
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Not item']]],
                    ],
                ],
            ],
        ];
        self::assertSame('- A', $this->converter->convert($adf));
    }

    public function testConvertTableCellEscapesPipeAndNewline(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'table',
                    'content' => [
                        [
                            'type' => 'tableRow',
                            'content' => [
                                ['type' => 'tableCell', 'content' => [['type' => 'text', 'text' => 'a|b']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $out = $this->converter->convert($adf);
        self::assertStringContainsString('\\|', $out);
    }

    public function testConvertApplyMarkEmptyTextReturnsEmpty(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => '', 'marks' => [['type' => 'strong']]],
                    ],
                ],
            ],
        ];
        self::assertSame('', $this->converter->convert($adf));
    }

    public function testConvertHeadingLevelClamped(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 10],
                    'content' => [['type' => 'text', 'text' => 'H']],
                ],
            ],
        ];
        self::assertSame('###### H', $this->converter->convert($adf));
    }

    public function testConvertInlineWithNonArrayMarks(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'x', 'marks' => 'invalid'],
                    ],
                ],
            ],
        ];
        self::assertSame('x', $this->converter->convert($adf));
    }

    public function testConvertUnknownMarkReturnsText(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'y', 'marks' => [['type' => 'unknownMark']]],
                    ],
                ],
            ],
        ];
        self::assertSame('y', $this->converter->convert($adf));
    }

    public function testConvertTableEmptyContentReturnsEmpty(): void
    {
        $adf = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => []]]];
        self::assertSame('', $this->converter->convert($adf));
    }

    public function testConvertTableNonArrayContentReturnsEmpty(): void
    {
        $adf = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => 'x']]];
        self::assertSame('', $this->converter->convert($adf));
    }

    public function testConvertSkipsNonArrayContentNodes(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A']]],
                'not-array',
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'B']]],
            ],
        ];
        self::assertSame("A\n\nB", $this->converter->convert($adf));
    }

    public function testConvertTopLevelListItem(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'listItem',
                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Solo item']]]],
                ],
            ],
        ];
        self::assertSame('Solo item', $this->converter->convert($adf));
    }

    public function testConvertListItemContentWithOnlyBlock(): void
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
                                ['type' => 'bulletList', 'content' => [
                                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nested only']]]]],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame('- - Nested only', $this->converter->convert($adf));
    }

    public function testConvertListItemContentSkipsNonArrayChild(): void
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
                                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A']]],
                                'not-array',
                                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'B']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame("- A\nB", $this->converter->convert($adf));
    }

    public function testConvertTableSkipsNonTableRow(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'table',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Not row']]],
                        ['type' => 'tableRow', 'content' => [
                            ['type' => 'tableCell', 'content' => [['type' => 'text', 'text' => 'Cell']]],
                        ]],
                    ],
                ],
            ],
        ];
        $out = $this->converter->convert($adf);
        self::assertStringContainsString('Cell', $out);
        self::assertStringContainsString('---', $out);
    }

    public function testConvertTableSkipsNonArrayCell(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'table',
                    'content' => [
                        [
                            'type' => 'tableRow',
                            'content' => [
                                ['type' => 'tableCell', 'content' => [['type' => 'text', 'text' => 'A']]],
                                'not-cell',
                                ['type' => 'tableCell', 'content' => [['type' => 'text', 'text' => 'B']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $out = $this->converter->convert($adf);
        self::assertStringContainsString('A', $out);
        self::assertStringContainsString('B', $out);
    }

    public function testConvertInlineContentSkipsNonArrayNodes(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'a'],
                        42,
                        ['type' => 'text', 'text' => 'b'],
                    ],
                ],
            ],
        ];
        self::assertSame('ab', $this->converter->convert($adf));
    }

    public function testConvertInlineSkipsNonArrayMarkInMarksList(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'strong'], 'invalid', ['type' => 'em']]],
                    ],
                ],
            ],
        ];
        $out = $this->converter->convert($adf);
        self::assertStringContainsString('x', $out);
        self::assertMatchesRegularExpression('/\*+.*\*+/', $out);
    }

    public function testGetInlineTextWithNestedContent(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'link',
                            'attrs' => ['href' => 'https://example.com'],
                            'content' => [['type' => 'text', 'text' => 'link text']],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame('link text', $this->converter->convert($adf));
    }

    public function testGetInlineTextUnknownTypeNoContentReturnsEmpty(): void
    {
        $adf = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'before'],
                        ['type' => 'unknownInline'],
                        ['type' => 'text', 'text' => 'after'],
                    ],
                ],
            ],
        ];
        self::assertSame('beforeafter', $this->converter->convert($adf));
    }
}
