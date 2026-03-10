<?php

declare(strict_types=1);

namespace App\Service;

use DH\Adf\Node\Block\Blockquote;
use DH\Adf\Node\Block\BulletList;
use DH\Adf\Node\Block\Document;
use DH\Adf\Node\Block\Heading as AdfHeading;
use DH\Adf\Node\Block\OrderedList;
use DH\Adf\Node\Block\Paragraph as AdfParagraph;
use DH\Adf\Node\Child\ListItem as AdfListItem;
use DH\Adf\Node\Inline\Text as AdfText;
use DH\Adf\Node\Mark\Code as CodeMark;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote as CMBlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Converts Markdown to Atlassian Document Format (ADF) using league/commonmark and adf-tools.
 */
class MarkdownToAdfConverter
{
    private MarkdownParser $parser;

    public function __construct(?MarkdownParser $parser = null)
    {
        $this->parser = $parser ?? self::createDefaultParser();
    }

    /**
     * Converts Markdown string to ADF array suitable for Jira description/comment.
     *
     * @return array{type: string, version: int, content: array<int, mixed>}
     */
    public function convert(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            $empty = (new Document())->paragraph()->end()->jsonSerialize();
            $doc = json_decode(json_encode($empty, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            \assert(isset($doc['type'], $doc['version'], $doc['content']));

            return $doc;
        }

        $cmDoc = $this->parser->parse($markdown);
        $adfDoc = new Document();

        foreach ($cmDoc->children() as $child) {
            $this->convertBlock($child, $adfDoc);
        }

        $doc = json_decode(json_encode($adfDoc->jsonSerialize(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        \assert(isset($doc['type'], $doc['version'], $doc['content']));

        return $doc;
    }

    /**
     * @param Document|Blockquote|BulletList|OrderedList|AdfListItem $adfParent ADF block with builder methods (paragraph, heading, etc.) from traits
     */
    private function convertBlock(Node $node, Document|Blockquote|BulletList|OrderedList|AdfListItem $adfParent): void
    {
        if ($node instanceof Paragraph) {
            $this->convertParagraphBlock($node, $adfParent);

            return;
        }
        if ($node instanceof Heading) {
            $this->convertHeadingBlock($node, $adfParent);

            return;
        }
        if ($node instanceof FencedCode) {
            $this->convertFencedCodeBlock($node, $adfParent);

            return;
        }
        if ($node instanceof ThematicBreak) {
            $adfParent->rule();

            return;
        }
        if ($node instanceof CMBlockQuote) {
            $this->convertBlockquoteBlock($node, $adfParent);

            return;
        }
        if ($node instanceof ListBlock) {
            $this->convertListBlock($node, $adfParent);

            return;
        }
        if ($node instanceof \League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode) {
            $adfParent->codeblock(null)->text($node->getLiteral())->end();
        }
    }

    private function convertParagraphBlock(Paragraph $node, Document|Blockquote|BulletList|OrderedList|AdfListItem $adfParent): void
    {
        $p = $adfParent->paragraph();
        $this->appendInlines($node, $p);
        $p->end();
    }

    private function convertHeadingBlock(Heading $node, Document|Blockquote|BulletList|OrderedList|AdfListItem $adfParent): void
    {
        $h = $adfParent->heading($node->getLevel());
        $this->appendInlines($node, $h);
        $h->end();
    }

    private function convertFencedCodeBlock(FencedCode $node, Document|Blockquote|BulletList|OrderedList|AdfListItem $adfParent): void
    {
        $info = $node->getInfoWords();
        $lang = $info !== [] ? $info[0] : null;
        $adfParent->codeblock($lang)->text($node->getLiteral())->end();
    }

    private function convertBlockquoteBlock(CMBlockQuote $node, Document|Blockquote|BulletList|OrderedList|AdfListItem $adfParent): void
    {
        $bq = $adfParent->blockquote();
        foreach ($node->children() as $child) {
            $this->convertBlock($child, $bq);
        }
        $bq->end();
    }

    private function convertListBlock(ListBlock $node, Document|Blockquote|BulletList|OrderedList|AdfListItem $adfParent): void
    {
        $isOrdered = $node->getListData()->type === ListBlock::TYPE_ORDERED;
        $list = $isOrdered ? $adfParent->orderedlist() : $adfParent->bulletlist();
        foreach ($node->children() as $item) {
            if ($item instanceof ListItem) {
                $li = $list->item();
                foreach ($item->children() as $itemChild) {
                    $this->convertBlock($itemChild, $li);
                }
                $li->end();
            }
        }
        $list->end();
    }

    /**
     * @param AdfParagraph|AdfHeading $adfBlock
     */
    private function appendInlines(Node $block, AdfParagraph|AdfHeading $adfBlock): void
    {
        $child = $block->firstChild();
        while ($child !== null) {
            if ($child instanceof Text) {
                $adfBlock->text($child->getLiteral());
            } elseif ($child instanceof Strong) {
                $adfBlock->strong($this->getInlineTextContent($child));
            } elseif ($child instanceof Emphasis) {
                $adfBlock->em($this->getInlineTextContent($child));
            } elseif ($child instanceof Code) {
                $adfBlock->append(new AdfText($child->getLiteral(), new CodeMark()));
            } elseif ($child instanceof Link) {
                $adfBlock->link($this->getInlineTextContent($child), $child->getUrl(), $child->getTitle());
            } else {
                $this->appendInlines($child, $adfBlock);
            }
            $child = $child->next();
        }
    }

    private function getInlineTextContent(Node $node): string
    {
        $out = '';
        $child = $node->firstChild();
        while ($child !== null) {
            if ($child instanceof Text) {
                $out .= $child->getLiteral();
            } else {
                $out .= $this->getInlineTextContent($child);
            }
            $child = $child->next();
        }

        return $out;
    }

    private static function createDefaultParser(): MarkdownParser
    {
        $environment = \League\CommonMark\Environment\Environment::createCommonMarkEnvironment();

        return new MarkdownParser($environment);
    }
}
