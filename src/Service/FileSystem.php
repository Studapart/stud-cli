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
        $adapter = new LocalFilesystemAdapter('/');

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
        $this->filesystem->write($path, $contents);
    }

    public function dirname(string $path): string
    {
        return dirname($path);
    }
}
