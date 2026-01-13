<?php

declare(strict_types=1);

namespace App\Service;

use League\HTMLToMarkdown\HtmlConverter as LeagueHtmlConverter;
use Stevebauman\Hypertext\Transformer;

/**
 * Converts Jira HTML content to various formats (PlainText, Markdown, AsciiDoc).
 * Implements HtmlConverterInterface to provide all conversion methods.
 */
class JiraHtmlConverter implements HtmlConverterInterface
{
    private readonly Transformer $transformer;
    private ?LeagueHtmlConverter $markdownConverter = null;
    private ?Logger $logger = null;

    public function __construct(
        ?Transformer $transformer = null,
        ?Logger $logger = null
    ) {
        $this->transformer = $transformer ?? new Transformer();
        $this->logger = $logger;
    }

    /**
     * Converts content to plain text suitable for terminal display.
     * Uses Stevebauman\Hypertext library for robust conversion.
     *
     * Note: This method converts HTML to plain text and handles <hr> tags by
     * converting them to dividers. Any further formatting, sanitization, or
     * section parsing should be done by the handler/display layer.
     */
    public function toPlainText(string $content): string
    {
        // Convert <hr> tags to divider markers before transformer processes them
        // The transformer's HtmlPurifier removes <hr> tags, so we need to convert them first
        // We'll replace <hr> with a pattern that will become a divider line after transformation
        // Using a pattern that will be preserved: newline + dashes + newline
        $content = preg_replace('/<hr\s*\/?>/i', "\n---\n", $content);
        $content = preg_replace('/<hr\s+[^>]*\/?>/i', "\n---\n", $content);

        $text = $this->transformer
            ->keepLinks()
            ->keepNewLines()
            ->toText($content);

        // Don't remove leading whitespace here - let the handler do it
        // This prevents accidentally removing non-whitespace characters
        return $text;
    }

    /**
     * Converts content to Markdown format for better readability on platforms like GitHub.
     * Uses league/html-to-markdown library for conversion.
     *
     * @param string $content The content to convert (typically HTML from Jira)
     * @return string The converted Markdown, or original content if conversion fails
     */
    public function toMarkdown(string $content): string
    {
        // Check for XML extension before attempting conversion
        // Cannot test extension_loaded() returning false in test environment
        // @codeCoverageIgnoreStart
        if (! extension_loaded('xml') && ! class_exists('DOMDocument')) {
            if ($this->logger !== null) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, 'PHP XML extension is not available. HTML to Markdown conversion disabled. Install php-xml extension.');
            }

            return $content;
        }
        // @codeCoverageIgnoreEnd

        try {
            if ($this->markdownConverter === null) {
                $this->markdownConverter = new LeagueHtmlConverter([
                    'hard_break' => true,
                    'strip_tags' => false,
                    'use_autolinks' => true,
                ]);
                $this->markdownConverter->getConfig()->setOption('header_style', 'atx');
            }

            return $this->markdownConverter->convert($content);
        } catch (\Exception $e) {
            // Check if exception is related to missing XML extension
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'DOMDocument') || str_contains($errorMessage, "Class 'DOMDocument' not found")) {
                if ($this->logger !== null) {
                    $this->logger->warning(Logger::VERBOSITY_NORMAL, 'PHP XML extension is not available. HTML to Markdown conversion disabled. Install php-xml extension.');
                }

                return $content;
            }

            // If conversion fails for other reasons, return original content as fallback
            return $content;
        }
    }

    /**
     * Converts content to AsciiDoc format.
     * Currently not implemented - returns content as-is.
     *
     * @param string $content The content to convert
     * @return string The converted AsciiDoc (or original content if not implemented)
     */
    public function toAsciiDoc(string $content): string
    {
        // TODO: Implement AsciiDoc conversion when needed
        // For now, return content as-is
        return $content;
    }
}
