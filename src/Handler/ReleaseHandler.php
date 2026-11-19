<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\ReleaseDocumentPaths;
use App\DTO\WorkflowRecorder;
use App\Enum\WorkflowChannel;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Response\WorkflowResponse;
use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\Prompt\PromptInterface;
use App\Service\ReleaseVersionBumper;

class ReleaseHandler implements GitRepositoryAware, ProjectBaseBranchAware
{
    private WorkflowEntryRecorder $recorder;

    private readonly ReleaseVersionBumper $versionBumper;

    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly PromptInterface $prompt,
        private readonly FileSystem $fileSystem,
        private readonly ReleaseDocumentPaths $paths = new ReleaseDocumentPaths(),
    ) {
        $this->versionBumper = new ReleaseVersionBumper($gitRepository, $fileSystem, $paths);
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
        try {
            $content = $this->fileSystem->read($this->paths->composerJson);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Unable to read composer.json', 0, $e);
        }

        $composerJson = json_decode($content, true);
        if (! is_array($composerJson) || ! isset($composerJson['version'])) {
            throw new \RuntimeException('Invalid composer.json format or missing version field');
        }

        return $composerJson['version'];
    }

    public function handle(?string $version = null, bool $publish = false, ?string $bumpType = null, bool $quiet = false): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $targetVersion = $version ?? $this->calculateNextVersion($this->getCurrentVersion(), $bumpType ?? 'patch');

        $this->recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.section', ['version' => $targetVersion]));

        $this->gitRepository->fetch();
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.fetched'), WorkflowChannel::Git);

        $releaseBranch = 'release/v' . $targetVersion;
        $this->gitRepository->createBranch($releaseBranch, 'origin/develop');
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.created_branch', ['branch' => $releaseBranch]), WorkflowChannel::Git);

        $this->versionBumper->bump($targetVersion, $this->recorder);

        $this->gitRepository->stageAllChanges();
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.staged'), WorkflowChannel::Git);

        $this->gitRepository->commit('chore(Version): Bump version to ' . $targetVersion);
        $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.committed'), WorkflowChannel::Git);

        if ($publish || (! $quiet && $this->prompt->confirm(MessageRef::key('release.confirm_publish'), false))) {
            $this->gitRepository->pushToOrigin($releaseBranch);
            $this->recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.published'), WorkflowChannel::Git);
        }

        $this->recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('release.success', ['version' => $targetVersion]));

        return $this->recorder->toResponse(0);
    }
}
