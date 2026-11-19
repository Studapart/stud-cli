<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;

/**
 * Activates a managed PHAR update: the new version is stored as its own file, then ~/.local/bin/stud is switched to it.
 * The running copy is never replaced.
 */
class PharVersionActivator
{
    public function __construct(
        private readonly ManagedInstallPaths $paths,
        private readonly ManagedSymlinkSwitcher $switcher,
    ) {
    }

    public function activate(UpdateInstallContext $context, string $downloadedPhar, string $version, WorkflowEntryRecorder $recorder): int
    {
        $symlink = $this->paths->managedSymlink();
        if (is_link($symlink) && ! $this->paths->isManagedLink($symlink)) {
            return $this->fail($recorder, 'update.phar_unmanaged_symlink');
        }
        if (is_file($symlink) && ! is_link($symlink) && realpath($symlink) !== realpath($context->binaryPath)) {
            return $this->fail($recorder, 'update.phar_unmanaged_file');
        }

        $target = $this->paths->pharFile($version);
        $activated = ManagedInstallPaths::isVersion($version)
            && $this->keepRunningCopy($symlink, $context->currentVersion)
            && $this->installFile($downloadedPhar, $target)
            && $this->switcher->switch($symlink, $target);
        if (! $activated) {
            return $this->fail($recorder, 'update.phar_install_failed');
        }

        $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.phar_success', ['version' => $version]));

        return 0;
    }

    /**
     * A plain file at the managed path is the running copy of a pre-managed install. Keep it as the current version's file.
     */
    private function keepRunningCopy(string $symlink, string $currentVersion): bool
    {
        if (! is_file($symlink) || is_link($symlink)) {
            return true;
        }
        if (! ManagedInstallPaths::isVersion($currentVersion)) {
            return false;
        }

        $copy = $this->paths->pharFile($currentVersion);

        return file_exists($copy) || $this->installFile($symlink, $copy);
    }

    private function fail(WorkflowEntryRecorder $recorder, string $translationKey): int
    {
        $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key($translationKey));

        return 1;
    }

    private function installFile(string $source, string $target): bool
    {
        @mkdir(dirname($target), 0777, true);
        $partial = $target . '.part';
        if (! @copy($source, $partial) || ! @chmod($partial, 0755) || ! @rename($partial, $target)) {
            @unlink($partial);

            return false;
        }

        return true;
    }
}
