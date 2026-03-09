<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Builds minimal Atlassian Document Format (ADF) for Jira description from plain text.
 */
class JiraAdfHelper
{
    /**
     * Converts plain text to minimal ADF doc (single or multiple paragraphs).
     *
     * @return array{type: string, version: int, content: array<int, array{type: string, content: array<int, array{type: string, text: string}>}>}
     */
    public static function plainTextToAdf(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [
                'type' => 'doc',
                'version' => 1,
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [],
                    ],
                ],
            ];
        }

        $paragraphs = preg_split('/\n\s*\n/', $text);
        $content = [];
        // preg_split with this pattern returns array; false only on error
        // @codeCoverageIgnoreStart
        if ($paragraphs === false) {
            $paragraphs = [];
        }
        // @codeCoverageIgnoreEnd

        foreach ($paragraphs as $para) {
            $para = trim($para);
            // preg_split(\n\s*\n) never yields a segment that trims to empty; branch is defensive
            // @codeCoverageIgnoreStart
            if ($para === '') {
                continue;
            }
            // @codeCoverageIgnoreEnd
            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $para,
                    ],
                ],
            ];
        }

        // Fallback when every split segment trimmed to empty (e.g. trim($text) is only newlines - edge case)
        // Unreachable with default trim; trim of all-whitespace is ''
        // @codeCoverageIgnoreStart
        if ($content === []) {
            $content[] = [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ];
        }
        // @codeCoverageIgnoreEnd

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content,
        ];
    }
}
