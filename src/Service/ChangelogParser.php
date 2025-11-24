<?php

namespace App\Service;

class ChangelogParser
{
    private const CHANGELOG_SECTION_BREAKING = 'breaking';

    /**
     * Parse changelog content and extract changes between current and latest versions.
     *
     * @param string $changelogContent The full changelog content
     * @param string $currentVersion Current version (e.g., "1.0.0" or "v1.0.0")
     * @param string $latestVersion Latest version (e.g., "1.0.1" or "v1.0.1")
     * @return array{sections: array<string, array<string>>, hasBreaking: bool, breakingChanges: array<string>}
     */
    public function parse(string $changelogContent, string $currentVersion, string $latestVersion): array
    {
        $currentVersion = $this->normalizeVersion($currentVersion);
        $latestVersion = $this->normalizeVersion($latestVersion);
        
        $result = [
            'sections' => [],
            'hasBreaking' => false,
            'breakingChanges' => [],
        ];

        $lines = explode("\n", $changelogContent);
        $inTargetVersion = false;
        $currentSection = null;
        
        foreach ($lines as $line) {
            $versionInChangelog = $this->extractVersionFromLine($line);
            
            if ($versionInChangelog !== null) {
                // Check if we've reached a version older than current (stop parsing)
                if (version_compare($versionInChangelog, $currentVersion, '<')) {
                    break;
                }
                
                // Check if this version is in the target range
                $inTargetVersion = $this->isInTargetVersion($versionInChangelog, $currentVersion, $latestVersion);
                $currentSection = null; // Reset section when entering new version
                continue;
            }

            if (!$inTargetVersion) {
                continue;
            }

            $section = $this->extractSectionFromLine($line);
            if ($section !== null) {
                $currentSection = $section;
                if ($currentSection === self::CHANGELOG_SECTION_BREAKING) {
                    $result['hasBreaking'] = true;
                }
                continue;
            }

            $item = $this->extractItemFromLine($line);
            if ($item !== null && $currentSection !== null) {
                if ($currentSection === self::CHANGELOG_SECTION_BREAKING) {
                    $result['hasBreaking'] = true;
                    $result['breakingChanges'][] = $item;
                } else {
                    if (!isset($result['sections'][$currentSection])) {
                        $result['sections'][$currentSection] = [];
                    }
                    $result['sections'][$currentSection][] = $item;
                }
            }
        }

        return $result;
    }

    /**
     * Normalize version string by removing 'v' prefix.
     */
    protected function normalizeVersion(string $version): string
    {
        return ltrim($version, 'v');
    }

    /**
     * Extract version number from a changelog line (e.g., "## [1.0.0] - 2025-01-01").
     *
     * @return string|null Version number if found, null otherwise
     */
    protected function extractVersionFromLine(string $line): ?string
    {
        if (preg_match('/^##\s+\[(\d+\.\d+\.\d+)\]/', $line, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Check if a version is in the target range (current < version <= latest).
     */
    protected function isInTargetVersion(string $versionInChangelog, string $currentVersion, string $latestVersion): bool
    {
        return version_compare($versionInChangelog, $latestVersion, '<=') && 
               version_compare($versionInChangelog, $currentVersion, '>');
    }

    /**
     * Extract section name from a changelog line (e.g., "### Added").
     *
     * @return string|null Section name in lowercase if found, null otherwise
     */
    protected function extractSectionFromLine(string $line): ?string
    {
        if (preg_match('/^###\s+(\w+)/', $line, $matches)) {
            return strtolower($matches[1]);
        }
        
        return null;
    }

    /**
     * Extract item text from a changelog line (e.g., "- Feature added").
     *
     * @return string|null Item text if found, null otherwise
     */
    protected function extractItemFromLine(string $line): ?string
    {
        if (preg_match('/^[\s*\-]+\s*(.+)$/', $line, $matches)) {
            $item = trim($matches[1]);
            return !empty($item) ? $item : null;
        }
        
        return null;
    }

    /**
     * Get formatted section title for display.
     */
    public function getSectionTitle(string $sectionType): string
    {
        return match(strtolower($sectionType)) {
            'added' => '### Added',
            'changed' => '### Changed',
            'deprecated' => '### Deprecated',
            'removed' => '### Removed',
            'fixed' => '### Fixed',
            self::CHANGELOG_SECTION_BREAKING => '### Breaking',
            'security' => '### Security',
            default => '### ' . ucfirst($sectionType),
        };
    }
}

