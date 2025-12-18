<?php

declare(strict_types=1);

namespace App\Service;

interface CanConvertToMarkdownInterface
{
    /**
     * Converts content to Markdown format.
     *
     * @param string $content The content to convert (HTML, Markdown, or other formats)
     * @return string The converted Markdown
     */
    public function toMarkdown(string $content): string;
}
