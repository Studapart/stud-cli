<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

class FileSystem
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly FileSystemPathPolicy $pathPolicy,
    ) {
    }

    /**
     * Creates a FileSystem instance with Local adapter (for production use).
     * Factory method for production use, tested via integration tests
     */
    // @codeCoverageIgnoreStart
    public static function createLocal(): self
    {
        $filesystem = new FlysystemFilesystem(new LocalFilesystemAdapter(getcwd() ?: '/'));

        return new self($filesystem, new FileSystemPathPolicy($filesystem));
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
        $this->pathPolicy->validate($path);

        // Use native operations for temp files or paths outside root
        if ($this->pathPolicy->shouldUseNativeOperations($path)) {
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
        $this->pathPolicy->validate($path);

        // Use read() method which handles paths outside Flysystem root
        $content = $this->read($path);

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
        $this->pathPolicy->validate($path);
        $yamlContent = \Symfony\Component\Yaml\Yaml::dump($data);
        $this->write($path, $yamlContent);
    }

    /**
     * Copies the current file contents to a sibling backup before mutation.
     *
     * @return string|null The backup path, or null when the source file does not exist
     * @throws \InvalidArgumentException If path contains invalid characters
     * @throws \RuntimeException If the source cannot be read or the backup cannot be written
     */
    public function backupFileIfExists(string $path): ?string
    {
        $this->pathPolicy->validate($path);
        if (! $this->fileExists($path)) {
            return null;
        }

        $backupPath = $this->generateBackupPath($path);
        $this->write($backupPath, $this->read($path));

        return $backupPath;
    }

    /**
     * Generates a non-overwriting sibling backup path.
     */
    protected function generateBackupPath(string $path): string
    {
        $basePath = $path . '.bak.' . date('YmdHis');
        if (! $this->fileExists($basePath)) {
            return $basePath;
        }

        $index = 1;
        do {
            $candidate = $basePath . '.' . $index;
            ++$index;
        } while ($this->fileExists($candidate));

        return $candidate;
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
        $this->pathPolicy->validate($path);

        // Use native operations for paths outside root
        if ($this->pathPolicy->shouldUseNativeOperations($path)) {
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
        $this->pathPolicy->validate($path);

        // Use native mkdir for absolute paths outside Flysystem root (e.g. ~/.config/stud when cwd is elsewhere).
        // Otherwise the directory would be created under getcwd() and config write would fail on macOS/Linux.
        if ($this->pathPolicy->shouldUseNativeOperations($path)) {
            if (! @mkdir($path, $mode, true) && ! is_dir($path)) {
                throw new \RuntimeException("Failed to create directory: {$path}");
            }

            return;
        }

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
        $this->pathPolicy->validate($path);
        $this->write($path, $contents);
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
        $this->pathPolicy->validate($path);

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
        $this->pathPolicy->validate($path);

        // Use native operations for temp files or paths outside root
        if ($this->pathPolicy->shouldUseNativeOperations($path)) {
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
        $this->pathPolicy->validate($path);

        // Use native operations for temp files or paths outside root
        if ($this->pathPolicy->shouldUseNativeOperations($path)) {
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
        $this->pathPolicy->validate($path);

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
        $this->pathPolicy->validate($path);

        // Use native operations for temp files or paths outside root
        if ($this->pathPolicy->shouldUseNativeOperations($path)) {
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
        $this->pathPolicy->validate($path);

        if ($this->pathPolicy->shouldUseNativeOperations($path)) {
            // @codeCoverageIgnoreStart
            return @chmod($path, $mode);
            // @codeCoverageIgnoreEnd
        }

        if (! $this->pathPolicy->isLocalFilesystem()) {
            return false;
        }

        $realPath = $this->pathPolicy->resolveLocalFilePath($path);
        if ($realPath === null || ! $this->filesystem->fileExists($path)) {
            return false;
        }

        // @codeCoverageIgnoreStart
        return @chmod($realPath, $mode);
        // @codeCoverageIgnoreEnd
    }
}
