<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Validates paths and decides whether a path is served by native PHP file functions or by Flysystem.
 */
class FileSystemPathPolicy
{
    private readonly bool $isLocalFilesystem;
    private readonly ?string $cachedCwd;

    public function __construct(
        private readonly FilesystemOperator $filesystem
    ) {
        $this->isLocalFilesystem = $this->determineIfLocalFilesystem();
        $this->cachedCwd = getcwd() ?: null;
    }

    /**
     * Validates that a path does not contain dangerous characters.
     *
     * @throws \InvalidArgumentException If path contains invalid characters
     */
    public function validate(string $path): void
    {
        // Reject paths with null bytes (potential security issue)
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Path contains null byte');
        }

        // Reject paths with control characters (except newline/tab which might be valid in some contexts)
        // But for filesystem paths, we should be strict
        if (preg_match('/[\x00-\x08\x0B-\x0C\x0E-\x1F]/', $path)) {
            throw new \InvalidArgumentException('Path contains invalid control characters');
        }
    }

    /**
     * Determines if a path should use native file operations instead of Flysystem.
     * This is used for temp files (/tmp/) that are outside the filesystem root.
     *
     * @return bool True if native operations should be used, false if Flysystem should be used
     */
    public function shouldUseNativeOperations(string $path): bool
    {
        // For temp files, use native operations if:
        // 1. Using in-memory filesystem (temp files need native ops)
        // 2. Using local filesystem but path is outside root
        if ($this->isUnderSystemTempDir($path)) {
            return ! $this->isLocalFilesystem || ! $this->isPathWithinRoot($path);
        }

        // Real absolute paths (e.g. /Users/foo/.config/stud/config.yml) must always use native PHP.
        // When running from a PHAR on macOS, getcwd() can be phar:///.../stud.phar, so Flysystem root
        // is inside the archive; using Flysystem would write the config inside the PHAR.
        if ($path !== '' && $path[0] === '/' && ! str_starts_with($path, 'phar://') && $this->isLocalFilesystem) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the filesystem is using a LocalFilesystemAdapter (determined at construction time).
     */
    public function isLocalFilesystem(): bool
    {
        return $this->isLocalFilesystem;
    }

    /**
     * Resolves a relative Flysystem path to an absolute local filesystem path
     * using reflection to access the adapter's root location.
     *
     * @return string|null The absolute local path, or null if resolution fails
     */
    public function resolveLocalFilePath(string $path): ?string
    {
        // Only caller (FileSystem::chmod) already guards isLocalFilesystem
        // @codeCoverageIgnoreStart
        if (! $this->filesystem instanceof FlysystemFilesystem) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($this->filesystem);
            $adapterProperty = $reflection->getProperty('adapter');
            $adapter = $adapterProperty->getValue($this->filesystem);

            if (! $adapter instanceof LocalFilesystemAdapter) {
                return null;
            }

            $adapterReflection = new \ReflectionClass($adapter);
            $rootLocationProperty = $adapterReflection->getProperty('rootLocation');
            $rootLocation = $rootLocationProperty->getValue($adapter);

            return $rootLocation . '/' . ltrim($path, '/');
        } catch (\ReflectionException $e) {
            return null;
        }
        // @codeCoverageIgnoreEnd
    }

    private function isUnderSystemTempDir(string $path): bool
    {
        if ($path === '' || $path[0] !== '/') {
            return false;
        }

        $normalizedPath = str_replace('\\', '/', $path);
        $candidates = array_unique([
            rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/'),
            '/tmp',
            '/private/tmp',
        ]);

        foreach ($candidates as $tempDir) {
            if (str_starts_with($normalizedPath, $tempDir . '/')
                || $normalizedPath === $tempDir) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a path is within the filesystem root (getcwd() for LocalFilesystemAdapter).
     * Uses realpath() for proper path normalization to prevent path traversal attacks.
     */
    private function isPathWithinRoot(string $path): bool
    {
        if (! str_starts_with($path, '/')) {
            return true;
        }

        // If using in-memory filesystem, always use filesystem methods (don't use native file ops)
        if (! $this->isLocalFilesystem) {
            return true;
        }

        // @codeCoverageIgnoreStart
        // getcwd() returns false when the CWD is deleted; not feasible to simulate in PHPUnit
        if ($this->cachedCwd === null) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        // realpath() resolves symlinks and normalizes .. sequences
        $normalizedPath = realpath($path);
        $normalizedCwd = realpath($this->cachedCwd);

        // @codeCoverageIgnoreStart
        // realpath() failure only occurs with invalid paths or filesystem errors; fall back to string comparison
        if ($normalizedPath === false) {
            $normalizedPath = $path;
        }
        if ($normalizedCwd === false) {
            $normalizedCwd = $this->cachedCwd;
        }
        // @codeCoverageIgnoreEnd

        return str_starts_with($normalizedPath, $normalizedCwd . DIRECTORY_SEPARATOR)
            || $normalizedPath === $normalizedCwd;
    }

    /**
     * Determines if the filesystem is using a LocalFilesystemAdapter.
     * Reflection runs once at construction time, not on every call.
     */
    private function determineIfLocalFilesystem(): bool
    {
        if (! $this->filesystem instanceof FlysystemFilesystem) {
            return false;
        }

        try {
            // Flysystem doesn't expose the adapter type, so read it through reflection
            $reflection = new \ReflectionClass($this->filesystem);
            $adapterProperty = $reflection->getProperty('adapter');
            $adapter = $adapterProperty->getValue($this->filesystem);

            return $adapter instanceof LocalFilesystemAdapter;
        } catch (\ReflectionException $e) { // @codeCoverageIgnore
            // Only occurs if the Flysystem adapter structure changes; assume not local (safer)
            return false; // @codeCoverageIgnore
        }
    }
}
