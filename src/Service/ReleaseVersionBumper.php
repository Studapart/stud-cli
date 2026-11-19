<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\ReleaseDocumentPaths;

/**
 * Writes a release version into the project files: composer.json, composer.lock, CHANGELOG.md, and README.md.
 */
class ReleaseVersionBumper
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly FileSystem $fileSystem,
        private readonly ReleaseDocumentPaths $paths,
    ) {
    }

    public function bump(string $version, WorkflowEntryRecorder $recorder): void
    {
        $this->updateComposerVersion($version);
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.updated_composer', ['version' => $version]));

        $this->gitRepository->run(['composer', 'update', '--lock']);
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.updated_lock'));

        $this->gitRepository->run(['composer', 'dump-config']);
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.dumped_config'));

        $this->updateChangelog($version);
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.updated_changelog', ['version' => $version]));

        $this->updateReadme($version);
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.updated_readme', ['version' => $version]));
    }

    protected function updateComposerVersion(string $version): void
    {
        try {
            $content = $this->fileSystem->read($this->paths->composerJson);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Unable to read composer.json', 0, $e);
        }

        $composerJson = json_decode($content, true);
        if (! is_array($composerJson)) {
            throw new \RuntimeException('Invalid composer.json format');
        }

        $composerJson['version'] = $version;
        $encoded = json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // @codeCoverageIgnoreStart
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode composer.json');
        }
        // @codeCoverageIgnoreEnd
        $this->fileSystem->write($this->paths->composerJson, $encoded);
    }

    protected function updateChangelog(string $version): void
    {
        try {
            $content = $this->fileSystem->read($this->paths->changelog);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Unable to read CHANGELOG.md', 0, $e);
        }

        $currentDate = date('Y-m-d');
        $newVersionHeader = "## [{$version}] - {$currentDate}";
        $unreleasedHeader = '## [Unreleased]';

        $replacement = $unreleasedHeader . "\n\n" . $newVersionHeader;
        $updatedContent = str_replace($unreleasedHeader, $replacement, $content);

        $this->fileSystem->write($this->paths->changelog, $updatedContent);
    }

    /**
     * Updates intentionally marked release examples in README.md.
     *
     * @param string $version The new version (e.g. '3.5.0')
     */
    protected function updateReadme(string $version): void
    {
        try {
            $content = $this->fileSystem->read($this->paths->readme);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Unable to read README.md', 0, $e);
        }

        $updated = preg_replace_callback(
            '/<!-- release-version:start -->(.*?)<!-- release-version:end -->/s',
            function (array $matches) use ($version): string {
                $block = preg_replace('/stud-\d+\.\d+\.\d+\.phar/', 'stud-' . $version . '.phar', $matches[1]);
                $block = preg_replace('#releases/download/v\d+\.\d+\.\d+/#', 'releases/download/v' . $version . '/', $block ?? $matches[1]);

                return '<!-- release-version:start -->' . ($block ?? $matches[1]) . '<!-- release-version:end -->';
            },
            $content
        );

        $this->fileSystem->write($this->paths->readme, $updated ?? $content);
    }
}
