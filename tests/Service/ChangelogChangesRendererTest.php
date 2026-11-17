<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\DTO\WorkflowOutputEntry;
use App\DTO\WorkflowRecorder;
use App\Service\ChangelogChangesRenderer;
use App\Service\ChangelogParser;
use PHPUnit\Framework\TestCase;

class ChangelogChangesRendererTest extends TestCase
{
    public function testRecordsNothingWithoutSectionsOrBreakingChanges(): void
    {
        $recorder = new WorkflowRecorder();

        (new ChangelogChangesRenderer(new ChangelogParser()))->render(
            $recorder,
            'v1.0.1',
            ['sections' => [], 'hasBreaking' => false, 'breakingChanges' => []]
        );

        $this->assertSame([], $recorder->getEntries());
    }

    public function testRecordsBreakingChangesThenNonEmptySections(): void
    {
        $recorder = new WorkflowRecorder();

        (new ChangelogChangesRenderer(new ChangelogParser()))->render($recorder, 'v1.0.1', [
            'sections' => ['added' => ['Feature A'], 'removed' => []],
            'hasBreaking' => true,
            'breakingChanges' => ['Rename command'],
        ]);

        $this->assertSame([
            ['section', 'update.changelog_section', ['version' => 'v1.0.1']],
            ['warning', 'update.breaking_changes_detected', []],
            ['writeln', '  <fg=red>⚠️  Rename command</>', null],
            ['newLine', null, null],
            ['text', '<fg=cyan>### Added</>', null],
            ['writeln', '  • Feature A', null],
            ['newLine', null, null],
        ], array_map($this->describe(...), $recorder->getEntries()));
    }

    public function testRecordsSectionsWithoutBreakingBlock(): void
    {
        $recorder = new WorkflowRecorder();

        (new ChangelogChangesRenderer(new ChangelogParser()))->render($recorder, 'v2.0.0', [
            'sections' => ['fixed' => ['Bug A', 'Bug B']],
            'hasBreaking' => false,
            'breakingChanges' => [],
        ]);

        $this->assertSame([
            ['section', 'update.changelog_section', ['version' => 'v2.0.0']],
            ['text', '<fg=cyan>### Fixed</>', null],
            ['writeln', '  • Bug A', null],
            ['writeln', '  • Bug B', null],
            ['newLine', null, null],
        ], array_map($this->describe(...), $recorder->getEntries()));
    }

    /**
     * @return array{string, mixed, array<string, mixed>|null}
     */
    private function describe(WorkflowOutputEntry $entry): array
    {
        if ($entry->message instanceof MessageRef) {
            return [$entry->type, $entry->message->key, $entry->message->parameters];
        }

        return [$entry->type, $entry->message, null];
    }
}
