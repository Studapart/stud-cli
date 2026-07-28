<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Service\Prompt\PromptInterface;

/**
 * Removes older portable version directories after a successful update.
 */
class PortableOldVersionCleaner
{
    private WorkflowEntryRecorder $recorder;

    public function __construct(
        protected readonly PromptInterface $prompt,
    ) {
    }

    public function cleanup(UpdateInstallContext $context, string $currentVersion, bool $quiet, WorkflowEntryRecorder $recorder): void
    {
        $this->recorder = $recorder;
        if (! $this->shouldCleanupOldVersions($quiet)) {
            return;
        }

        $platformRoot = $context->portableRoot . '/' . $context->platform;
        $partition = $this->partitionCleanupTargets(
            $this->oldVersionDirectories($platformRoot, $currentVersion),
            $context->bundleRoot
        );

        $this->removeImmediateVersions($partition['immediate']);
        $this->scheduleDeferredRemoval($partition['deferred']);
    }

    protected function shouldCleanupOldVersions(bool $quiet): bool
    {
        if ($quiet) {
            return false;
        }

        return $this->prompt->confirm(MessageRef::key('update.portable_cleanup_prompt'), false);
    }

    /**
     * Splits old version directories into safe immediate deletes and deferred (running bundle).
     *
     * @param list<string> $candidates
     * @return array{immediate: list<string>, deferred: list<string>}
     */
    protected function partitionCleanupTargets(array $candidates, ?string $runningBundle): array
    {
        $immediate = [];
        $deferred = [];
        foreach ($candidates as $path) {
            if ($runningBundle !== null && $this->isSameFilesystemPath($path, $runningBundle)) {
                $deferred[] = $path;

                continue;
            }

            $immediate[] = $path;
        }

        return ['immediate' => $immediate, 'deferred' => $deferred];
    }

    protected function isSameFilesystemPath(string $left, string $right): bool
    {
        $resolvedLeft = realpath($left);
        $resolvedRight = realpath($right);
        if ($resolvedLeft !== false && $resolvedRight !== false) {
            return $resolvedLeft === $resolvedRight;
        }

        return rtrim($left, '/') === rtrim($right, '/');
    }

    /**
     * @param list<string> $paths
     */
    protected function removeImmediateVersions(array $paths): void
    {
        foreach ($paths as $path) {
            if ($this->removeDirectory($path) && ! is_dir($path)) {
                continue;
            }

            $this->recorder->addWarning(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('update.portable_cleanup_failed', ['path' => $path])
            );
        }
    }

    /**
     * Schedules removal after this PHP process exits so the running phar stays readable.
     *
     * @param list<string> $paths
     */
    protected function scheduleDeferredRemoval(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $pid = $this->currentProcessId();
        if ($pid === false || ! $this->spawnDeferredRemoval($pid, $paths)) {
            $this->warnDeferredScheduleFailures($paths);

            return;
        }

        $this->recorder->addNote(
            WorkflowEntryRecorder::VERBOSITY_NORMAL,
            MessageRef::key('update.portable_cleanup_deferred', [
                'paths' => implode(', ', $paths),
            ])
        );
    }

    /**
     * @param list<string> $paths
     */
    protected function warnDeferredScheduleFailures(array $paths): void
    {
        foreach ($paths as $path) {
            $this->recorder->addWarning(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('update.portable_cleanup_schedule_failed', ['path' => $path])
            );
        }
    }

    /**
     * @param list<string> $paths
     */
    protected function spawnDeferredRemoval(int $pid, array $paths): bool
    {
        $exitCode = 0;
        $output = [];
        exec($this->buildDeferredRemovalCommand($pid, $paths), $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * @param list<string> $paths
     */
    protected function buildDeferredRemovalCommand(int $pid, array $paths): string
    {
        $escapedPaths = implode(' ', array_map(static fn (string $path): string => escapeshellarg($path), $paths));
        $script = sprintf(
            'while kill -0 %d 2>/dev/null; do sleep 0.2; done; rm -rf %s',
            $pid,
            $escapedPaths
        );

        return 'nohup sh -c ' . escapeshellarg($script) . ' >/dev/null 2>&1 &';
    }

    protected function currentProcessId(): int|false
    {
        return getmypid();
    }

    /**
     * @return list<string>
     */
    protected function oldVersionDirectories(string $platformRoot, string $currentVersion): array
    {
        $items = scandir($platformRoot);
        if ($items === false) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }

        $versions = [];
        foreach ($items as $item) {
            $path = $platformRoot . '/' . $item;
            if ($item === '.' || $item === '..' || $item === $currentVersion || ! is_dir($path) || is_link($path)) {
                continue;
            }

            $versions[] = $path;
        }

        return $versions;
    }

    /**
     * Recursively removes a path. Returns true when the path is gone or was never present.
     */
    public function removeDirectory(string $path): bool
    {
        if (! file_exists($path) && ! is_link($path)) {
            return true;
        }

        if (! is_dir($path) || is_link($path)) {
            return @unlink($path);
        }

        return $this->removeDirectoryContents($path) && @rmdir($path);
    }

    protected function removeDirectoryContents(string $path): bool
    {
        $items = scandir($path);
        if ($items === false) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (! $this->removeDirectory($path . '/' . $item)) {
                return false;
            }
        }

        return true;
    }
}
