<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

class FileSystem
{
    public function __construct(
        private readonly FilesystemOperator $filesystem
    ) {
    }

    /**
     * Creates a FileSystem instance with Local adapter (for production use).
     */
    public static function createLocal(): self
    {
        $adapter = new LocalFilesystemAdapter(getcwd() ?: '/');

        return new self(new FlysystemFilesystem($adapter));
    }

    public function fileExists(string $path): bool
    {
        return $this->filesystem->fileExists($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path): array
    {
        try {
            $content = $this->filesystem->read($path);
        } catch (\League\Flysystem\FilesystemException $e) {
            throw new \RuntimeException("Failed to read file: {$path}", 0, $e);
        }

        return \Symfony\Component\Yaml\Yaml::parse($content);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function dumpFile(string $path, array $data): void
    {
        $yamlContent = \Symfony\Component\Yaml\Yaml::dump($data);
        $this->filesystem->write($path, $yamlContent);
    }

    public function isDir(string $path): bool
    {
        // If path is absolute and outside the filesystem root, use native is_dir
        if (str_starts_with($path, '/') && ! $this->isPathWithinRoot($path)) {
            return is_dir($path);
        }

        return $this->filesystem->directoryExists($path);
    }

    public function mkdir(string $path, int $mode = 0777, bool $recursive = false): bool
    {
        try {
            $this->filesystem->createDirectory($path);
        } catch (\League\Flysystem\FilesystemException $e) {
            return false;
        }

        return true;
    }

    public function filePutContents(string $path, string $contents): void
    {
        // If path is absolute and outside the filesystem root, use native file_put_contents
        // This handles system temp directories and other absolute paths
        if (str_starts_with($path, '/') && ! $this->isPathWithinRoot($path)) {
            $result = @file_put_contents($path, $contents);
            if ($result === false) {
                throw new \RuntimeException("Failed to write file: {$path}");
            }

            return;
        }

        $this->filesystem->write($path, $contents);
    }

    /**
     * Checks if a path is within the filesystem root.
     * For LocalFilesystemAdapter, this checks if the path is within getcwd().
     *
     * @param string $path The path to check
     * @return bool True if path is within root, false otherwise
     */
    private function isPathWithinRoot(string $path): bool
    {
        if (! str_starts_with($path, '/')) {
            // Relative paths are always within root
            return true;
        }

        // If using in-memory filesystem, always use filesystem methods (don't use native file ops)
        if (! $this->isLocalFilesystem()) {
            return true;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            return false;
        }

        // Check if path starts with current working directory
        return str_starts_with($path, $cwd . '/') || $path === $cwd;
    }

    /**
     * Checks if the filesystem is using a LocalFilesystemAdapter.
     * This is needed to determine if we can use native file operations for paths outside the root.
     *
     * @return bool True if using LocalFilesystemAdapter, false otherwise
     */
    private function isLocalFilesystem(): bool
    {
        if (! $this->filesystem instanceof FlysystemFilesystem) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($this->filesystem);
            $adapterProperty = $reflection->getProperty('adapter');
            $adapterProperty->setAccessible(true);
            $adapter = $adapterProperty->getValue($this->filesystem);

            return $adapter instanceof \League\Flysystem\Local\LocalFilesystemAdapter;
        } catch (\Exception $e) {
            // If we can't determine, assume it's not local (safer to use filesystem methods)
            return false;
        }
    }

    public function dirname(string $path): string
    {
        return dirname($path);
    }

    /**
     * Reads a file and returns its content as a string.
     */
    public function read(string $path): string
    {
        // If path is absolute and outside the filesystem root, use native file_get_contents
        if (str_starts_with($path, '/') && ! $this->isPathWithinRoot($path)) {
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
     */
    public function delete(string $path): bool
    {
        // If path is absolute and outside the filesystem root, use native unlink
        if (str_starts_with($path, '/') && ! $this->isPathWithinRoot($path)) {
            return @unlink($path);
        }

        try {
            $this->filesystem->delete($path);

            return true;
        } catch (\League\Flysystem\FilesystemException $e) {
            return false;
        }
    }

    /**
     * Lists files and directories in a directory.
     *
     * @return array<string> Array of file/directory names
     */
    public function listDirectory(string $path): array
    {
        try {
            $listing = $this->filesystem->listContents($path, false);
            $files = [];
            foreach ($listing as $item) {
                $files[] = $item->path();
            }

            return $files;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Writes content to a file (non-YAML).
     */
    public function write(string $path, string $contents): void
    {
        $this->filesystem->write($path, $contents);
    }

    /**
     * Changes file permissions (chmod).
     * Note: This only works with LocalFilesystemAdapter.
     */
    public function chmod(string $path, int $mode): bool
    {
        try {
            // Flysystem doesn't have native chmod support, but we can use the adapter directly
            // if it's a LocalFilesystemAdapter
            if ($this->filesystem instanceof FlysystemFilesystem) {
                // @codeCoverageIgnoreStart
                // Reflection operations that may throw exceptions are difficult to test in isolation
                $reflection = new \ReflectionClass($this->filesystem);
                $adapterProperty = $reflection->getProperty('adapter');
                $adapterProperty->setAccessible(true);
                $adapter = $adapterProperty->getValue($this->filesystem);
                // @codeCoverageIgnoreEnd

                if ($adapter instanceof \League\Flysystem\Local\LocalFilesystemAdapter) {
                    // @codeCoverageIgnoreStart
                    // Reflection operations that may throw exceptions are difficult to test in isolation
                    $adapterReflection = new \ReflectionClass($adapter);
                    $rootLocationProperty = $adapterReflection->getProperty('rootLocation');
                    $rootLocationProperty->setAccessible(true);
                    $rootLocation = $rootLocationProperty->getValue($adapter);
                    // @codeCoverageIgnoreEnd
                    $realPath = $rootLocation . '/' . ltrim($path, '/');
                    if ($realPath !== null && $this->filesystem->fileExists($path)) {
                        // Use native chmod for local filesystem only
                        // chmod on local filesystem is tested via integration
                        // @codeCoverageIgnoreStart
                        return @chmod($realPath, $mode);
                        // @codeCoverageIgnoreEnd
                    }
                }
            }

            return false;
            // @codeCoverageIgnoreStart
            // Exception handling for reflection failures is difficult to test in isolation
        } catch (\Exception $e) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }
}
