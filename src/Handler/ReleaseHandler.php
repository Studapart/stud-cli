<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReleaseHandler
{
    // @codeCoverageIgnoreStart
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly TranslationService $translator,
        private readonly Logger $logger,
        private readonly string $composerJsonPath = 'composer.json',
        private readonly string $changelogPath = 'CHANGELOG.md',
        private readonly ?FileSystem $fileSystem = null
    ) {
    }
    // @codeCoverageIgnoreEnd

    private function getFileSystem(): FileSystem
    {
        return $this->fileSystem ?? FileSystem::createLocal();
    }

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
        $fileSystem = $this->getFileSystem();

        try {
            $content = $fileSystem->read($this->composerJsonPath);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Unable to read composer.json', 0, $e);
        }

        $composerJson = json_decode($content, true);
        if (! is_array($composerJson) || ! isset($composerJson['version'])) {
            throw new \RuntimeException('Invalid composer.json format or missing version field');
        }

        return $composerJson['version'];
    }

    public function handle(SymfonyStyle $io, ?string $version = null, bool $publish = false, ?string $bumpType = null): void
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

        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.section', ['version' => $targetVersion]));

        $this->gitRepository->fetch();
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.fetched'));

        $releaseBranch = 'release/v' . $targetVersion;
        $this->gitRepository->createBranch($releaseBranch, 'origin/develop');
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.created_branch', ['branch' => $releaseBranch]));

        $this->updateComposerVersion($targetVersion);
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.updated_composer', ['version' => $targetVersion]));

        $this->gitRepository->run('composer update --lock');
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.updated_lock'));

        $this->gitRepository->run('composer dump-config');
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.dumped_config'));

        $this->updateChangelog($targetVersion);
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.updated_changelog', ['version' => $targetVersion]));

        $this->gitRepository->stageAllChanges();
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.staged'));

        $this->gitRepository->commit('chore(Version): Bump version to ' . $targetVersion);
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.committed'));

        if ($publish) {
            $this->gitRepository->pushToOrigin($releaseBranch);
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.published'));
        } else {
            if ($this->logger->confirm($this->translator->trans('release.confirm_publish'), false)) {
                $this->gitRepository->pushToOrigin($releaseBranch);
                $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.published'));
            }
        }

        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('release.success', ['version' => $targetVersion]));
    }

    protected function updateComposerVersion(string $version): void
    {
        $fileSystem = $this->getFileSystem();

        try {
            $content = $fileSystem->read($this->composerJsonPath);
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
        $fileSystem->write($this->composerJsonPath, $encoded);
    }

    protected function updateChangelog(string $version): void
    {
        $fileSystem = $this->getFileSystem();

        try {
            $content = $fileSystem->read($this->changelogPath);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Unable to read CHANGELOG.md', 0, $e);
        }

        $currentDate = date('Y-m-d');
        $newVersionHeader = "## [{$version}] - {$currentDate}";
        $unreleasedHeader = '## [Unreleased]';

        // Replace ## [Unreleased] with ## [Unreleased] followed by the new version header
        $replacement = $unreleasedHeader . "\n\n" . $newVersionHeader;
        $updatedContent = str_replace($unreleasedHeader, $replacement, $content);

        $fileSystem->write($this->changelogPath, $updatedContent);
    }
}
