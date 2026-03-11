<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Helper for markdown content that will be displayed on GitHub/GitLab.
 * Unescapes backslash-bracket sequences so task list checkboxes render correctly.
 */
class MarkdownHelper
{
    /**
     * Unescapes backslash-bracket sequences so GitHub/GitLab task list checkboxes render correctly.
     * Content from HTML-to-Markdown conversion or other tools often escapes [ ] as \[ \] or \[\],
     * which would break checkboxes.
     */
    public static function unescapeCheckboxMarkdown(string $body): string
    {
        return str_replace(['\]', '\['], [']', '['], $body);
    }
}
