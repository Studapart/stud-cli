<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;

/**
 * Records parsed changelog changes (breaking changes first, then each section) for the update output.
 */
class ChangelogChangesRenderer
{
    public function __construct(
        private readonly ChangelogParser $changelogParser,
    ) {
    }

    /**
     * @param array{sections: array<string, array<string>>, hasBreaking: bool, breakingChanges: array<string>} $changes
     */
    public function render(WorkflowEntryRecorder $recorder, string $tagName, array $changes): void
    {
        if (empty($changes['sections']) && ! $changes['hasBreaking']) {
            return;
        }

        $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.changelog_section', ['version' => $tagName]));

        if ($changes['hasBreaking']) {
            $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.breaking_changes_detected'));
            foreach ($changes['breakingChanges'] as $breakingChange) {
                $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, "  <fg=red>⚠️  {$breakingChange}</>");
            }
            $recorder->addNewLine(WorkflowEntryRecorder::VERBOSITY_NORMAL);
        }

        foreach ($changes['sections'] as $sectionType => $items) {
            if (empty($items)) {
                continue;
            }

            $sectionTitle = $this->changelogParser->getSectionTitle($sectionType);
            $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, "<fg=cyan>{$sectionTitle}</>");
            foreach ($items as $item) {
                $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, "  • {$item}");
            }
            $recorder->addNewLine(WorkflowEntryRecorder::VERBOSITY_NORMAL);
        }
    }
}
