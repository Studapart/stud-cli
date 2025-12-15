<?php

declare(strict_types=1);

/**
 * Color configuration for stud-cli console output.
 * Supports light and dark themes for different terminal backgrounds.
 *
 * Theme naming:
 * - "light" = bright colors for dark terminal backgrounds (default)
 * - "dark" = muted colors for light terminal backgrounds
 *
 * @return array{light: array<string, string>, dark: array<string, string>}
 */
return [
    // =====================================================================
    // NOTE ON THEME INVERSION:
    // Due to tool architecture, the labels are inverted relative to the background.
    // 'dark' array = Configuration applied to LIGHT backgrounds.
    // 'light' array = Configuration applied to DARK backgrounds.
    // =====================================================================

    // --- Config for LIGHT Backgrounds (Keyed as 'dark') ---
    // AESTHETIC: "Modern Print" - Deep, saturated inks on stark white paper.
    // High contrast, sophisticated, moving away from standard "web blue".
    'dark' => [
        // Status colors (Deep, rich tones distinct from the identity colors)
        'success' => '#059669',   // Emerald Ink
        'error' => '#DC2626',     //Deep Crimson
        // Standard yellow is invisible on white. Using a dark amber.
        'warning' => '#D97706',   // Amber

        // General Messages
        'info' => '#2563EB',      // Rich Royal Blue
        'note' => '#2563EB',
        'text' => '#111827',      // Near-Black Charcoal (softer than pure black)
        'comment' => '#6B7280',   // Cool Slate Grayish-Blue

        // NEW: System-Specific Messages (The Identity)
        // Jira: A deep, confident Sapphire blue that anchors the structure.
        'jira_message' => '#1E40AF',
        // Git: A high-impact Crimson red for action and urgency.
        'git_message' => '#BE123C',

        // Presentation Components
        'table_header' => '#1E40AF', // Sapphire headers
        'table_row' => '#111827',    // Charcoal text
        'table_border' => '#CBD5E1', // subtle blue-gray border ink

        // Definition list colors
        'definition_key' => '#1E40AF',
        'definition_value' => '#111827',

        // Content colors
        'section_title' => '#1E40AF',
        'listing_item' => '#111827',
        'text_content' => '#111827',
    ],

    // --- Config for DARK Backgrounds (Keyed as 'light') ---
    // AESTHETIC: "Cyber-Noir" - High energy glowing filaments in a dark void.
    // High luminosity, vibrant, digital feel.
    'light' => [
        // Status colors (Electric, glowing variants)
        'success' => '#34D399',   // Electric Mint
        'error' => '#F87171',     // Glowing Coral Red
        'warning' => '#FBBF24',   // Warm Digital Gold

        // General Messages
        'info' => '#06B6D4',      // Cyan
        'note' => '#06B6D4',
        'text' => '#F0F9FF',      // "Ice White" (very faint cool tint, less sterile)
        'comment' => '#64748B',   // Deep Slate Blue Grey (recedes into background)

        // NEW: System-Specific Messages (The Identity)
        // Jira: A vibrant, piercing Electric Cyan that commands attention.
        'jira_message' => '#06B6D4',
        // Git: A hyper-saturated Neon Orange for distinct action flow.
        'git_message' => '#FF7E33',

        // Presentation Components (Jira Structure Dominant)
        'table_header' => '#06B6D4', // Electric Cyan headers
        'table_row' => '#F0F9FF',    // Ice White text
        // A very deep, low-saturation teal for subtle structure that doesn't compete.
        'table_border' => '#164E63',

        // Definition list colors
        'definition_key' => '#06B6D4',
        'definition_value' => '#F0F9FF',

        // Content colors
        'section_title' => '#06B6D4',
        'listing_item' => '#F0F9FF',
        'text_content' => '#F0F9FF',
    ],
];
