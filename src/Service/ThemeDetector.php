<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Detects terminal theme (light or dark) to apply appropriate color scheme.
 */
class ThemeDetector
{
    /**
     * Detects terminal theme based on environment variables and terminal capabilities.
     * Returns 'light' for dark terminals (default) or 'dark' for light terminals.
     */
    public function detect(): string
    {
        // Check COLORFGBG environment variable (common in terminals)
        // Format: "foreground;background" where 0-7 are standard colors
        // Dark terminals typically have high background values (0-7), light terminals have low (8-15)
        $colorfgbg = getenv('COLORFGBG');
        if ($colorfgbg !== false) {
            $parts = explode(';', $colorfgbg);
            if (count($parts) >= 2) {
                $background = (int) $parts[1];
                // Background values 0-7 are typically dark, 8-15 are light
                if ($background >= 8) {
                    return 'dark';
                }
            }
        }

        // Check TERM_PROGRAM for common terminal emulators
        $termProgram = getenv('TERM_PROGRAM');
        if ($termProgram !== false) {
            // Some terminals set this to indicate light mode
            if (str_contains(strtolower($termProgram), 'light')) {
                return 'dark';
            }
        }

        // Default: assume dark terminal (use light theme colors)
        return 'light';
    }
}
