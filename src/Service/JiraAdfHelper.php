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
            return self::buildDocNode([self::buildParagraphNode()]);
        }

        $paragraphs = preg_split('/\n\s*\n/', $text);
        // preg_split with this pattern returns array; false only on error
        // @codeCoverageIgnoreStart
        if ($paragraphs === false) {
            $paragraphs = [];
        }
        // @codeCoverageIgnoreEnd

        $content = [];
        foreach ($paragraphs as $para) {
            $para = trim($para);
            // preg_split(\n\s*\n) never yields a segment that trims to empty; branch is defensive
            // @codeCoverageIgnoreStart
            if ($para === '') {
                continue;
            }
            // @codeCoverageIgnoreEnd
            $content[] = self::buildParagraphNode($para);
        }

        // Unreachable with default trim; trim of all-whitespace is ''
        // @codeCoverageIgnoreStart
        if ($content === []) {
            $content[] = self::buildParagraphNode($text);
        }
        // @codeCoverageIgnoreEnd

        return self::buildDocNode($content);
    }

    /**
     * @param array<int, array{type: string, content: array<int, array{type: string, text: string}>}> $content
     * @return array{type: string, version: int, content: array<int, array{type: string, content: array<int, array{type: string, text: string}>}>}
     */
    private static function buildDocNode(array $content): array
    {
        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content,
        ];
    }

    /**
     * @return array{type: string, content: array<int, array{type: string, text: string}>}
     */
    private static function buildParagraphNode(string $text = ''): array
    {
        if ($text === '') {
            return ['type' => 'paragraph', 'content' => []];
        }

        return [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];
    }
}
