<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;

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
     * Register color styles and render a formatted section title.
     *
     * Combines the "register styles" and "section title" boilerplate into one call.
     *
     * @param array<string, string|int> $params Translation parameters
     */
    public function initSection(SymfonyStyle $io, string $transKey, array $params = []): void
    {
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        $title = $this->translator->trans($transKey, $params);
        if ($this->colorHelper !== null) {
            $title = $this->colorHelper->format('section_title', $title);
        }
        $io->section($title);
    }

    /**
     * Write a verbose comment line (only when -v is active).
     *
     * Uses colorHelper 'comment' style when available, falls back to <fg=gray>.
     *
     * @param array<string, string|int> $params Translation parameters
     */
    public function verboseComment(SymfonyStyle $io, string $transKey, array $params = []): void
    {
        if (! $io->isVerbose()) {
            return;
        }

        $io->writeln('  ' . $this->formatComment($this->translator->trans($transKey, $params)));
    }

    /**
     * Write a verbose note line (only when -v is active).
     *
     * @param array<string, string|int> $params Translation parameters
     */
    public function verboseNote(SymfonyStyle $io, string $transKey, array $params = []): void
    {
        if (! $io->isVerbose()) {
            return;
        }

        $io->note('  ' . $this->formatComment($this->translator->trans($transKey, $params)));
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
