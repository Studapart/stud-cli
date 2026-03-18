<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Shared presentation helpers for Responders.
 *
 * Encapsulates the repeated boilerplate that every Responder implements:
 * color style registration, formatted section titles, and verbose comments
 * with colorHelper/fallback formatting.
 *
 * Injected via composition (ADR-005), not inherited.
 */
class ResponderHelper
{
    public function __construct(
        public readonly TranslationService $translator,
        public readonly ?ColorHelper $colorHelper = null,
    ) {
    }

    /**
     * Register color styles and render a formatted section title via Logger (ADR-005).
     *
     * @param array<string, string|int> $params Translation parameters
     */
    public function initSection(Logger $logger, string $transKey, array $params = []): void
    {
        if ($this->colorHelper !== null) {
            $logger->registerStyles($this->colorHelper);
        }

        $title = $this->translator->trans($transKey, $params);
        if ($this->colorHelper !== null) {
            $title = $this->colorHelper->format('section_title', $title);
        }
        $logger->section(Logger::VERBOSITY_NORMAL, $title);
    }

    /**
     * Write a verbose comment line (only when -v is active) via Logger.
     *
     * @param array<string, string|int> $params Translation parameters
     */
    public function verboseComment(Logger $logger, string $transKey, array $params = []): void
    {
        $logger->comment(Logger::VERBOSITY_VERBOSE, '  ' . $this->formatComment($this->translator->trans($transKey, $params)));
    }

    /**
     * Write a verbose note line (only when -v is active) via Logger.
     *
     * @param array<string, string|int> $params Translation parameters
     */
    public function verboseNote(Logger $logger, string $transKey, array $params = []): void
    {
        $logger->note(Logger::VERBOSITY_VERBOSE, '  ' . $this->formatComment($this->translator->trans($transKey, $params)));
    }

    /**
     * Format a message with the 'comment' color style or <fg=gray> fallback.
     */
    public function formatComment(string $message): string
    {
        if ($this->colorHelper !== null) {
            return $this->colorHelper->format('comment', $message);
        }

        return "<fg=gray>{$message}</>";
    }
}
