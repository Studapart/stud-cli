<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Removes older versions after the operator consented during an update.
 *
 * An update only writes a consent marker. A later stud command that already runs the active version does the removal,
 * so the copy being deleted is never the one that is running.
 */
class OldVersionCleaner
{
    public function __construct(
        protected readonly ManagedInstallPaths $paths,
    ) {
    }

    public function markPending(): bool
    {
        $marker = $this->paths->cleanupMarker();
        @mkdir(dirname($marker), 0777, true);

        return @touch($marker);
    }

    /**
     * Removes every non-hidden, non-symlink sibling of the active entry when $runningPath runs from it.
     *
     * @return list<string> paths that could not be removed; the marker stays when this list is not empty
     */
    public function runPending(string $runningPath): array
    {
        if (! $this->paths->isConfigured() || ! is_file($this->paths->cleanupMarker())) {
            return [];
        }

        $active = $this->paths->activeEntry();
        if ($active === null || ! $this->isRunningFrom($runningPath, $active)) {
            return [];
        }

        $siblings = $this->siblingsOf($active);
        if ($siblings === false) {
            return [dirname($active)];
        }

        $failed = [];
        foreach ($siblings as $sibling) {
            if (! $this->removeDirectory($sibling)) {
                $failed[] = $sibling;
            }
        }

        if ($failed === []) {
            @unlink($this->paths->cleanupMarker());
        }

        return $failed;
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

    protected function isRunningFrom(string $runningPath, string $active): bool
    {
        $resolved = realpath($runningPath) ?: $runningPath;

        return $resolved === $active || str_starts_with($resolved, $active . '/');
    }

    /**
     * @return list<string>|false false when the directory holding the active entry cannot be listed
     */
    protected function siblingsOf(string $active): array|false
    {
        $items = @scandir(dirname($active));
        if ($items === false) {
            return false;
        }

        $siblings = [];
        foreach ($items as $item) {
            $path = dirname($active) . '/' . $item;
            if (str_starts_with($item, '.') || $path === $active || is_link($path)) {
                continue;
            }

            $siblings[] = $path;
        }

        return $siblings;
    }

    protected function removeDirectoryContents(string $path): bool
    {
        $items = @scandir($path);
        if ($items === false) {
            return false;
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
