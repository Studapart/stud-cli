<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Exception\ApiException;

class UpdateChangelogPresenter
{
    public function __construct(
        private readonly ChangelogParser $changelogParser,
        private readonly string $currentVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $release
     */
    public function display(
        WorkflowEntryRecorder $recorder,
        GithubProvider $githubProvider,
        array $release,
        callable $logVerbose,
    ): void {
        try {
            $tagName = $release['tag_name'] ?? 'unknown';
            $latestVersion = ltrim($tagName, 'v');
            $changelogContent = $githubProvider->getChangelogContent($tagName);
            $changes = $this->changelogParser->parse($changelogContent, $this->currentVersion, $latestVersion);

            $sections = $changes['sections'];
            $hasBreaking = $changes['hasBreaking'];

            if (empty($sections) && ! $hasBreaking) {
                return;
            }

            $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.changelog_section', ['version' => $tagName]));

            if ($hasBreaking) {
                $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.breaking_changes_detected'));
                foreach ($changes['breakingChanges'] as $breakingChange) {
                    $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, "  <fg=red>⚠️  {$breakingChange}</>");
                }
                $recorder->addNewLine(WorkflowEntryRecorder::VERBOSITY_NORMAL);
            }

            foreach ($sections as $sectionType => $items) {
                // @codeCoverageIgnoreStart
                if (empty($items)) {
                    continue;
                }
                // @codeCoverageIgnoreEnd

                $sectionTitle = $this->changelogParser->getSectionTitle($sectionType);
                $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, "<fg=cyan>{$sectionTitle}</>");
                foreach ($items as $item) {
                    $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_NORMAL, "  • {$item}");
                }
                $recorder->addNewLine(WorkflowEntryRecorder::VERBOSITY_NORMAL);
            }
        } catch (ApiException $e) {
            $logVerbose('Could not fetch changelog', $e->getMessage());
            $recorder->addText(WorkflowEntryRecorder::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $logVerbose('Could not fetch changelog', $e->getMessage());
        }
    }
}
