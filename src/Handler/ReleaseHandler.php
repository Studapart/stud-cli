<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\WorkflowOutput;

class ReleaseHandler
{
    // @codeCoverageIgnoreStart
    public function __construct(
        private readonly GitRepository $gitRepository,
        mixed $_translator,
        private readonly WorkflowOutput $logger,
        private readonly FileSystem $fileSystem,
        private readonly string $composerJsonPath = 'composer.json',
        private readonly string $changelogPath = 'CHANGELOG.md',
        private readonly string $readmePath = 'README.md'
    ) {
        unset($_translator);
    }
    // @codeCoverageIgnoreEnd

    /**
     * Calculates the next version based on SemVer rules.
     *
     * @param string $currentVersion The current version (e.g., '2.6.2')
     * @param string $bumpType The type of bump: 'major', 'minor', or 'patch'
     * @return string The next version
     */
    protected function calculateNextVersion(string $currentVersion, string $bumpType): string
    {
        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $currentVersion, $matches)) {
            throw new \RuntimeException("Invalid version format: {$currentVersion}. Expected format: X.Y.Z");
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = (int) $matches[3];

        return match ($bumpType) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'patch' => $major . '.' . $minor . '.' . ($patch + 1),
            default => throw new \RuntimeException("Invalid bump type: {$bumpType}. Must be 'major', 'minor', or 'patch'"),
        };
    }

    /**
     * Gets the current version from composer.json.
     *
     * @return string The current version
     */
    protected function getCurrentVersion(): string
    {
        try {
            $content = $this->fileSystem->read($this->composerJsonPath);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Unable to read composer.json', 0, $e);
        }

        $composerJson = json_decode($content, true);
        if (! is_array($composerJson) || ! isset($composerJson['version'])) {
            throw new \RuntimeException('Invalid composer.json format or missing version field');
        }

        return $composerJson['version'];
    }

    public function handle(?string $version = null, bool $publish = false, ?string $bumpType = null, bool $quiet = false): void
    {
        // Determine the target version
        if ($version !== null) {
            // Explicit version provided, use it
            $targetVersion = $version;
        } elseif ($bumpType !== null) {
            // Bump type provided, calculate from current version
            $currentVersion = $this->getCurrentVersion();
            $targetVersion = $this->calculateNextVersion($currentVersion, $bumpType);
        } else {
            // Default to patch bump
            $currentVersion = $this->getCurrentVersion();
            $targetVersion = $this->calculateNextVersion($currentVersion, 'patch');
        }

        $this->logger->addSection(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.section', ['version' => $targetVersion]));

        $this->gitRepository->fetch();
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.fetched'));

        $releaseBranch = 'release/v' . $targetVersion;
        $this->gitRepository->createBranch($releaseBranch, 'origin/develop');
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.created_branch', ['branch' => $releaseBranch]));

        $this->updateComposerVersion($targetVersion);
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.updated_composer', ['version' => $targetVersion]));

        $this->gitRepository->run('composer update --lock');
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.updated_lock'));

        $this->gitRepository->run('composer dump-config');
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.dumped_config'));

        $this->updateChangelog($targetVersion);
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.updated_changelog', ['version' => $targetVersion]));

        $this->updateReadme($targetVersion);
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.updated_readme', ['version' => $targetVersion]));

        $this->gitRepository->stageAllChanges();
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.staged'));

        $this->gitRepository->commit('chore(Version): Bump version to ' . $targetVersion);
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.committed'));

        if ($publish) {
            $this->gitRepository->pushToOrigin($releaseBranch);
            $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.published'));
        } else {
            if (! $quiet && $this->logger->confirm(MessageRef::key('release.confirm_publish'), false)) {
                $this->gitRepository->pushToOrigin($releaseBranch);
                $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.published'));
            }
        }

        $this->logger->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('release.success', ['version' => $targetVersion]));
    }

    protected function updateComposerVersion(string $version): void
    {
        try {
            $content = $this->fileSystem->read($this->composerJsonPath);
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
        // json_encode returning false is extremely rare (only with circular references or invalid UTF-8)
        // and is difficult to test in isolation
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode composer.json');
        }
        // @codeCoverageIgnoreEnd
        $this->fileSystem->write($this->composerJsonPath, $encoded);
    }

    protected function updateChangelog(string $version): void
    {
        try {
            $content = $this->fileSystem->read($this->changelogPath);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Unable to read CHANGELOG.md', 0, $e);
        }

        $currentDate = date('Y-m-d');
        $newVersionHeader = "## [{$version}] - {$currentDate}";
        $unreleasedHeader = '## [Unreleased]';

        // Replace ## [Unreleased] with ## [Unreleased] followed by the new version header
        $replacement = $unreleasedHeader . "\n\n" . $newVersionHeader;
        $updatedContent = str_replace($unreleasedHeader, $replacement, $content);

        $this->fileSystem->write($this->changelogPath, $updatedContent);
    }

    /**
     * Updates intentionally marked release examples in README.md.
     *
     * @param string $version The new version (e.g. '3.5.0')
     */
    protected function updateReadme(string $version): void
    {
        try {
            $content = $this->fileSystem->read($this->readmePath);
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

        $this->fileSystem->write($this->readmePath, $updated ?? $content);
    }
}
