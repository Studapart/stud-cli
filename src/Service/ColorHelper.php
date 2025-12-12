<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ColorHelper service for registering and applying color styles from configuration.
 * Uses Symfony's OutputFormatterStyle to register custom color tags.
 */
class ColorHelper
{
    /**
     * @param array<string, string> $colors Color configuration from colours.php
     */
    public function __construct(
        private readonly array $colors
    ) {
    }

    /**
     * Registers all color styles from configuration with the output formatter.
     * This should be called once before rendering any output.
     *
     * @param SymfonyStyle|OutputInterface $outputOrIo Either SymfonyStyle or OutputInterface
     */
    public function registerStyles(SymfonyStyle|OutputInterface $outputOrIo): void
    {
        $output = $outputOrIo instanceof SymfonyStyle
            ? $this->getOutputFromSymfonyStyle($outputOrIo)
            : $outputOrIo;

        $formatter = $output->getFormatter();

        foreach ($this->colors as $name => $color) {
            // Skip if style already exists (allows overriding)
            if ($formatter->hasStyle($name)) {
                continue;
            }

            // Convert color name to Symfony format
            $symfonyColor = $this->convertColorName($color);

            // Determine options (bold for bright colors to make them stand out)
            $options = [];
            if ($this->isBrightColor($color)) {
                $options = ['bold'];
            }

            // Create and register the style
            $style = new OutputFormatterStyle($symfonyColor, null, $options);
            $formatter->setStyle($name, $style);
        }
    }

    /**
     * Converts color names from config to Symfony Console format.
     * Handles both standard colors and bright variants.
     *
     * @return string Symfony color name
     */
    protected function convertColorName(string $colorName): string
    {
        // Symfony Console supports these colors:
        // black, red, green, yellow, blue, magenta, cyan, white
        // Bright variants can be achieved with options like ['bold']

        // Handle bright variants - use base color, brightness handled via options
        if (str_starts_with($colorName, 'bright-')) {
            return str_replace('bright-', '', $colorName);
        }

        // Handle dark variants (for light theme)
        if (str_starts_with($colorName, 'dark')) {
            return str_replace('dark', '', $colorName);
        }

        // Return as-is for standard colors
        return $colorName;
    }

    /**
     * Checks if a color name represents a bright variant.
     */
    protected function isBrightColor(string $colorName): bool
    {
        return str_starts_with($colorName, 'bright-');
    }

    /**
     * Formats a message with a color tag.
     *
     * @param string $colorName Color name from config (e.g., 'jira_message', 'table_header')
     * @param string $message Message to format
     * @return string Formatted message with color tag
     */
    public function format(string $colorName, string $message): string
    {
        return "<{$colorName}>{$message}</>";
    }

    /**
     * Gets the color name for a given key, with fallback.
     *
     * @return string Color name or 'white' as default
     */
    public function getColorName(string $key): string
    {
        return $this->colors[$key] ?? 'white';
    }

    /**
     * Extracts OutputInterface from SymfonyStyle using reflection.
     * SymfonyStyle stores the output as a private property.
     */
    private function getOutputFromSymfonyStyle(SymfonyStyle $io): OutputInterface
    {
        $reflection = new \ReflectionClass($io);
        $property = $reflection->getProperty('output');
        $property->setAccessible(true);

        return $property->getValue($io);
    }
}
