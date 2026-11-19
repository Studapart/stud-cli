<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\Exception\ApiException;

class UpdateChangelogPresenter
{
    private readonly ChangelogChangesRenderer $changesRenderer;

    public function __construct(
        private readonly ChangelogParser $changelogParser,
        private readonly string $currentVersion,
    ) {
        $this->changesRenderer = new ChangelogChangesRenderer($changelogParser);
    }

    /**
     * @param array<string, mixed> $release
     */
    public function display(
        WorkflowEntryRecorder $recorder,
        GithubGitHostingAdapter $githubProvider,
        array $release,
        callable $logVerbose,
    ): void {
        try {
            $tagName = $release['tag_name'] ?? 'unknown';
            $latestVersion = ltrim($tagName, 'v');
            $changelogContent = $githubProvider->getChangelogContent($tagName);
            $changes = $this->changelogParser->parse($changelogContent, $this->currentVersion, $latestVersion);
            $this->changesRenderer->render($recorder, $tagName, $changes);
        } catch (ApiException $e) {
            $logVerbose('Could not fetch changelog', $e->getMessage());
            $recorder->addText(WorkflowEntryRecorder::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $logVerbose('Could not fetch changelog', $e->getMessage());
        }
    }
}
