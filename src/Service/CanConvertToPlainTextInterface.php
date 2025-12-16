<?php

declare(strict_types=1);

namespace App\Service;

interface CanConvertToPlainTextInterface
{
    /**
     * Converts content to plain text format.
     *
     * @param string $content The content to convert (HTML, Markdown, or other formats)
     * @return string The converted plain text
     */
    public function toPlainText(string $content): string;
}
