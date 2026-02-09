<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MarkdownHelper;
use PHPUnit\Framework\TestCase;

class MarkdownHelperTest extends TestCase
{
    public function testUnescapeCheckboxMarkdownConvertsEscapedCheckboxes(): void
    {
        $body = "- \[ \] Unchecked item\n- \[x] Checked item";
        $result = MarkdownHelper::unescapeCheckboxMarkdown($body);

        $this->assertSame("- [ ] Unchecked item\n- [x] Checked item", $result);
    }

    public function testUnescapeCheckboxMarkdownLeavesNormalBrackets(): void
    {
        $body = "Text with [link](url) and [REF-123].";
        $result = MarkdownHelper::unescapeCheckboxMarkdown($body);

        $this->assertSame($body, $result);
    }

    public function testUnescapeCheckboxMarkdownEmptyString(): void
    {
        $this->assertSame('', MarkdownHelper::unescapeCheckboxMarkdown(''));
    }
}
