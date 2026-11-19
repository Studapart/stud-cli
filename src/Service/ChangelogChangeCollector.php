<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Accumulates changelog items per section while a changelog is parsed, tracking breaking changes separately.
 * Sections and items are only collected inside a version that is in the target range.
 */
class ChangelogChangeCollector
{
    public const SECTION_BREAKING = 'breaking';

    private bool $collecting = false;

    private ?string $currentSection = null;

    /** @var array<string, array<string>> */
    private array $sections = [];

    private bool $hasBreaking = false;

    /** @var array<string> */
    private array $breakingChanges = [];

    public function enterVersion(bool $inTargetRange): void
    {
        $this->collecting = $inTargetRange;
        $this->currentSection = null;
    }

    public function enterSection(string $section): void
    {
        if (! $this->collecting) {
            return;
        }
        $this->currentSection = $section;
        if ($section === self::SECTION_BREAKING) {
            $this->hasBreaking = true;
        }
    }

    public function addItem(string $item): void
    {
        if ($this->currentSection === null) {
            return;
        }
        if ($this->currentSection === self::SECTION_BREAKING) {
            $this->breakingChanges[] = $item;

            return;
        }
        $this->sections[$this->currentSection][] = $item;
    }

    /**
     * @return array{sections: array<string, array<string>>, hasBreaking: bool, breakingChanges: array<string>}
     */
    public function toArray(): array
    {
        return [
            'sections' => $this->sections,
            'hasBreaking' => $this->hasBreaking,
            'breakingChanges' => $this->breakingChanges,
        ];
    }
}
