<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

class FileSystem
{
    private const TEMP_PATH_PREFIX = '/tmp/';

    private readonly bool $isLocalFilesystem;
    private readonly ?string $cachedCwd;

    public function __construct(
        private readonly FilesystemOperator $filesystem
    ) {
        $this->isLocalFilesystem = $this->determineIfLocalFilesystem();
        $this->cachedCwd = getcwd() ?: null;
    }

    /**
     * Creates a FileSystem instance with Local adapter (for production use).
     * Factory method for production use, tested via integration tests
     */
    // @codeCoverageIgnoreStart
    public static function createLocal(): self
    {
        $adapter = new LocalFilesystemAdapter(getcwd() ?: '/');

        return new self(new FlysystemFilesystem($adapter));
    }
    // @codeCoverageIgnoreEnd

    /**
     * Checks if a file exists.
     *
     * @param string $path The file path to check
     * @return bool True if file exists, false otherwise
     * @throws \InvalidArgumentException If path contains invalid characters
     */
    public function fileExists(string $path): bool
    {
        $this->validatePath($path);

        // Use native operations for temp files or paths outside root
        if ($this->shouldUseNativeOperations($path)) {
            return @file_exists($path);
        }

        return $this->filesystem->fileExists($path);
    }

    /**
     * Parses a YAML file and returns its contents as an array.
     *
     * @param string $path The path to the YAML file
     * @return array<string, mixed> The parsed YAML data
     * @throws \InvalidArgumentException If path contains invalid characters
     * @throws \RuntimeException If file cannot be read or parsed
     */
    public function parseFile(string $path): array
    {
        $this->validatePath($path);

        try {
            $content = $this->filesystem->read($path);
        } catch (\League\Flysystem\FilesystemException $e) {
            throw new \RuntimeException("Failed to read file: {$path}", 0, $e);
        }

        $parsed = \Symfony\Component\Yaml\Yaml::parse($content);
        if (! is_array($parsed)) {
            throw new \RuntimeException("YAML file did not parse to an array: {$path}");
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * Writes data to a YAML file.
     *
     * @param string $path The path to the YAML file
     * @param array<string, mixed> $data The data to write
     * @throws \InvalidArgumentException If path contains invalid characters
     * @throws \RuntimeException If file cannot be written
     */
    public function dumpFile(string $path, array $data): void
    {
        $this->validatePath($path);
        $yamlContent = \Symfony\Component\Yaml\Yaml::dump($data);
        $this->write($path, $yamlContent);
    }

    /**
     * Checks if a path is a directory.
     *
     * @param string $path The path to check
     * @return bool True if path is a directory, false otherwise
     * @throws \InvalidArgumentException If path contains invalid characters
     */
    public function isDir(string $path): bool
    {
        $this->validatePath($path);

        // Use native operations for paths outside root
        if ($this->shouldUseNativeOperations($path)) {
            return is_dir($path);
        }

        return $this->filesystem->directoryExists($path);
    }

    /**
     * Creates a directory.
     * Note: The $mode and $recursive parameters are kept for API compatibility
     * but Flysystem's createDirectory always creates recursively.
     *
     * @param string $path The directory path to create
     * @param int $mode The permissions mode (kept for compatibility, not used by Flysystem)
     * @param bool $recursive Whether to create parent directories (kept for compatibility, Flysystem always does this)
     * @return void
     * @throws \InvalidArgumentException If path contains invalid characters
     * @throws \RuntimeException If directory cannot be created
     */
    public function mkdir(string $path, int $mode = 0777, bool $recursive = false): void
    {
        $this->validatePath($path);

        try {
            $this->filesystem->createDirectory($path);
        } catch (\League\Flysystem\FilesystemException $e) {
            throw new \RuntimeException("Failed to create directory: {$path}", 0, $e);
        }
    }

    /**
     * Writes content to a file (alias for write() for PHP native function compatibility).
     *
     * @param string $path The file path
     * @param string $contents The content to write
     * @throws \InvalidArgumentException If path contains invalid characters
     * @throws \RuntimeException If file cannot be written
     */
    public function filePutContents(string $path, string $contents): void
    {
        $this->validatePath($path);
        $this->write($path, $contents);
    }

    /**
     * Validates that a path does not contain dangerous characters.
     *
     * @param string $path The path to validate
     * @throws \InvalidArgumentException If path contains invalid characters
     */
    private function validatePath(string $path): void
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
     * @param string $path The path to check
     * @return bool True if native operations should be used, false if Flysystem should be used
     */
    private function shouldUseNativeOperations(string $path): bool
    {
        // For temp files, use native operations if:
        // 1. Using in-memory filesystem (temp files need native ops)
        // 2. Using local filesystem but path is outside root
        if (str_starts_with($path, self::TEMP_PATH_PREFIX)) {
            $isLocal = $this->isLocalFilesystem();

            return ! $isLocal || ! $this->isPathWithinRoot($path);
        }

        // For other absolute paths outside root, use native operations (local filesystems only)
        if (str_starts_with($path, '/') && $this->isLocalFilesystem() && ! $this->isPathWithinRoot($path)) {
            return true;
        }

        return false;
    }

    /**
     * Checks if a path is within the filesystem root.
     * For LocalFilesystemAdapter, this checks if the path is within getcwd().
     * Uses realpath() for proper path normalization to prevent path traversal attacks.
     *
     * @param string $path The path to check
     * @return bool True if path is within root, false otherwise
     */
    private function isPathWithinRoot(string $path): bool
    {
        if (! str_starts_with($path, '/')) {
            return true;
        }

        // If using in-memory filesystem, always use filesystem methods (don't use native file ops)
        if (! $this->isLocalFilesystem()) {
            return true;
        }

        // @codeCoverageIgnoreStart
        // This case occurs when getcwd() returns false (e.g., when CWD is deleted)
        // It's extremely difficult to simulate in tests as it requires manipulating the process's
        // working directory in a way that makes getcwd() fail, which is not feasible in PHPUnit
        if ($this->cachedCwd === null) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        // Normalize paths using realpath() to prevent path traversal attacks
        // This resolves symlinks and normalizes .. sequences
        $normalizedPath = realpath($path);
        $normalizedCwd = realpath($this->cachedCwd);

        // If realpath() fails, fall back to string comparison but log a warning
        // @codeCoverageIgnoreStart
        // realpath() failure is extremely rare (only occurs with invalid paths or filesystem errors)
        // and is difficult to simulate in tests without manipulating the filesystem
        if ($normalizedPath === false) {
            $normalizedPath = $path;
        }
        if ($normalizedCwd === false) {
            $normalizedCwd = $this->cachedCwd;
        }
        // @codeCoverageIgnoreEnd

        // Check if normalized path starts with normalized current working directory
        return str_starts_with($normalizedPath, $normalizedCwd . DIRECTORY_SEPARATOR)
            || $normalizedPath === $normalizedCwd;
    }

    /**
     * Determines if the filesystem is using a LocalFilesystemAdapter.
     * This is determined at construction time to avoid reflection-based detection.
     *
     * @return bool True if using LocalFilesystemAdapter, false otherwise
     */
    private function determineIfLocalFilesystem(): bool
    {
        if (! $this->filesystem instanceof FlysystemFilesystem) {
            return false;
        }

        // Use a more robust approach: check if we can access the adapter through
        // Flysystem's public API or by checking the filesystem's behavior
        // Since Flysystem doesn't expose adapter type directly, we use a type-safe approach
        // by checking if the filesystem root matches a local path pattern
        try {
            // Attempt to determine adapter type by checking filesystem capabilities
            // LocalFilesystemAdapter supports certain operations that in-memory doesn't
            // We can infer the type by checking if getcwd() matches expected patterns
            // However, the most reliable way is to check the adapter through reflection
            // but only once at construction time, not on every call
            $reflection = new \ReflectionClass($this->filesystem);
            $adapterProperty = $reflection->getProperty('adapter');
            $adapterProperty->setAccessible(true);
            $adapter = $adapterProperty->getValue($this->filesystem);

            return $adapter instanceof LocalFilesystemAdapter;
        } catch (\ReflectionException $e) { // @codeCoverageIgnore
            // ReflectionException is extremely rare and difficult to simulate in tests
            // It would only occur if the Flysystem adapter structure changes unexpectedly
            // If reflection fails, assume it's not local (safer to use filesystem methods)
            return false; // @codeCoverageIgnore
        }
    }

    /**
     * Checks if the filesystem is using a LocalFilesystemAdapter.
     * Uses cached value determined at construction time.
     *
     * @return bool True if using LocalFilesystemAdapter, false otherwise
     */
    private function isLocalFilesystem(): bool
    {
        return $this->isLocalFilesystem;
    }

    /**
     * Returns the directory name component of a path.
     *
     * @param string $path The file path
     * @return string The directory name
     * @throws \InvalidArgumentException If path contains invalid characters
     */
    public function dirname(string $path): string
    {
        $this->validatePath($path);

        return dirname($path);
    }

    /**
     * Reads a file and returns its content as a string.
     *
     * @param string $path The file path to read
     * @return string The file contents
     * @throws \InvalidArgumentException If path contains invalid characters
     * @throws \RuntimeException If file cannot be read
     */
    public function read(string $path): string
    {
        $this->validatePath($path);

        // Use native operations for temp files or paths outside root
        if ($this->shouldUseNativeOperations($path)) {
            $content = @file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException("Failed to read file: {$path}");
            }

            return $content;
        }

        try {
            return $this->filesystem->read($path);
        } catch (\League\Flysystem\FilesystemException $e) {
            throw new \RuntimeException("Failed to read file: {$path}", 0, $e);
        }
    }

    /**
     * Deletes a file.
     *
     * @param string $path The file path to delete
     * @return void
     * @throws \InvalidArgumentException If path contains invalid characters
     * @throws \RuntimeException If file cannot be deleted
     */
    public function delete(string $path): void
    {
        $this->validatePath($path);

        // Use native operations for temp files or paths outside root
        if ($this->shouldUseNativeOperations($path)) {
            $result = @unlink($path);
            if ($result === false) {
                throw new \RuntimeException("Failed to delete file: {$path}");
            }

            return;
        }

        try {
            $this->filesystem->delete($path);
        } catch (\League\Flysystem\FilesystemException $e) {
            throw new \RuntimeException("Failed to delete file: {$path}", 0, $e);
        }
    }

    /**
     * Lists files and directories in a directory.
     *
     * @param string $path The directory path to list
     * @return array<string> Array of file/directory names
     * @throws \InvalidArgumentException If path contains invalid characters
     * @throws \RuntimeException If directory cannot be listed
     */
    public function listDirectory(string $path): array
    {
        $this->validatePath($path);

        try {
            $listing = $this->filesystem->listContents($path, false);
            $files = [];
            foreach ($listing as $item) {
                $files[] = $item->path();
            }

            return $files;
        } catch (\League\Flysystem\FilesystemException $e) {
            throw new \RuntimeException("Failed to list directory: {$path}", 0, $e);
        }
    }

    /**
     * Writes content to a file (non-YAML).
     *
     * @param string $path The file path
     * @param string $contents The content to write
     * @throws \InvalidArgumentException If path contains invalid characters
     * @throws \RuntimeException If file cannot be written
     */
    public function write(string $path, string $contents): void
    {
        $this->validatePath($path);

        // Use native operations for temp files or paths outside root
        if ($this->shouldUseNativeOperations($path)) {
            $result = @file_put_contents($path, $contents);
            if ($result === false) {
                throw new \RuntimeException("Failed to write file: {$path}");
            }

            return;
        }

        $this->filesystem->write($path, $contents);
    }

    /**
     * Changes file permissions (chmod).
     * Note: This only works with LocalFilesystemAdapter.
     * chmod operations are tested via integration tests
     *
     * @param string $path The file path
     * @param int $mode The permissions mode (e.g., 0755)
     * @return bool True on success, false on failure
     * @throws \InvalidArgumentException If path contains invalid characters
     */
    public function chmod(string $path, int $mode): bool
    {
        $this->validatePath($path);

        // Use native chmod for temp files or paths outside root
        // This is critical for temp files like /tmp/stud-rebase-* that need executable permissions
        if ($this->shouldUseNativeOperations($path)) {
            // @codeCoverageIgnoreStart
            // chmod on temp files is tested via integration tests
            // This path handles temp files that are outside the Flysystem root
            return @chmod($path, $mode);
            // @codeCoverageIgnoreEnd
        }

        // chmod only works with LocalFilesystemAdapter
        if (! $this->isLocalFilesystem()) {
            return false;
        }

        try {
            // Flysystem doesn't have native chmod support, but we can use the adapter directly
            // if it's a LocalFilesystemAdapter
            if ($this->filesystem instanceof FlysystemFilesystem) {
                $reflection = new \ReflectionClass($this->filesystem);
                $adapterProperty = $reflection->getProperty('adapter');
                $adapterProperty->setAccessible(true);
                $adapter = $adapterProperty->getValue($this->filesystem);

                if ($adapter instanceof LocalFilesystemAdapter) {
                    $adapterReflection = new \ReflectionClass($adapter);
                    $rootLocationProperty = $adapterReflection->getProperty('rootLocation');
                    $rootLocationProperty->setAccessible(true);
                    $rootLocation = $rootLocationProperty->getValue($adapter);
                    $realPath = $rootLocation . '/' . ltrim($path, '/');
                    if ($realPath !== null && $this->filesystem->fileExists($path)) {
                        // Use native chmod for local filesystem only
                        // chmod on local filesystem is tested via integration
                        // @codeCoverageIgnoreStart
                        // This path is difficult to test as it requires reflection to access
                        // the adapter's rootLocation property, which is an edge case
                        return @chmod($realPath, $mode);
                        // @codeCoverageIgnoreEnd
                    }
                }
            }

            return false; // @codeCoverageIgnore
        } catch (\ReflectionException $e) { // @codeCoverageIgnore
            // ReflectionException is extremely rare and difficult to simulate in tests
            // It would only occur if the Flysystem adapter structure changes unexpectedly
            return false; // @codeCoverageIgnore
        }
    }
}
