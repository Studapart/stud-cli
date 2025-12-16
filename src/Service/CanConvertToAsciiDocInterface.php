<?php

declare(strict_types=1);

namespace App\Service;

interface CanConvertToAsciiDocInterface
{
    /**
     * Converts content to AsciiDoc format.
     *
     * @param string $content The content to convert (HTML, Markdown, or other formats)
     * @return string The converted AsciiDoc
     */
    public function toAsciiDoc(string $content): string;
}
