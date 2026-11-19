<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ChangelogChangeCollector;
use PHPUnit\Framework\TestCase;

class ChangelogChangeCollectorTest extends TestCase
{
    public function testIgnoresSectionsAndItemsOutsideTargetVersion(): void
    {
        $collector = new ChangelogChangeCollector();
        $collector->enterSection(ChangelogChangeCollector::SECTION_BREAKING);
        $collector->addItem('Before any version');
        $collector->enterVersion(false);
        $collector->enterSection('added');
        $collector->addItem('Out of range');

        $this->assertSame(
            ['sections' => [], 'hasBreaking' => false, 'breakingChanges' => []],
            $collector->toArray()
        );
    }

    public function testIgnoresItemsBeforeFirstSectionOfTargetVersion(): void
    {
        $collector = new ChangelogChangeCollector();
        $collector->enterVersion(true);
        $collector->addItem('No section yet');

        $this->assertSame([], $collector->toArray()['sections']);
    }

    public function testEnteringBreakingSectionFlagsBreakingWithoutItems(): void
    {
        $collector = new ChangelogChangeCollector();
        $collector->enterVersion(true);
        $collector->enterSection('added');
        $this->assertFalse($collector->toArray()['hasBreaking']);

        $collector->enterSection(ChangelogChangeCollector::SECTION_BREAKING);

        $this->assertSame(
            ['sections' => [], 'hasBreaking' => true, 'breakingChanges' => []],
            $collector->toArray()
        );
    }

    public function testGroupsItemsBySectionAcrossVersionsAndKeepsBreakingChangesSeparate(): void
    {
        $collector = new ChangelogChangeCollector();
        $collector->enterVersion(true);
        $collector->enterSection('added');
        $collector->addItem('Feature A');
        $collector->enterSection(ChangelogChangeCollector::SECTION_BREAKING);
        $collector->addItem('Rename command');
        $collector->enterVersion(true);
        $collector->addItem('Dropped: section reset by new version');
        $collector->enterSection('added');
        $collector->addItem('Feature B');
        $collector->enterSection('fixed');
        $collector->addItem('Bug fix');

        $this->assertSame([
            'sections' => ['added' => ['Feature A', 'Feature B'], 'fixed' => ['Bug fix']],
            'hasBreaking' => true,
            'breakingChanges' => ['Rename command'],
        ], $collector->toArray());
    }
}
