<?php

declare(strict_types=1);

namespace App\Service;

/**
 * @codeCoverageIgnore
 */
class FileSystem
{
    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseFile(string $path): array
    {
        return \Symfony\Component\Yaml\Yaml::parseFile($path);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function dumpFile(string $path, array $data): void
    {
        file_put_contents($path, \Symfony\Component\Yaml\Yaml::dump($data));
    }

    public function isDir(string $path): bool
    {
        return is_dir($path);
    }

    public function mkdir(string $path, int $mode = 0777, bool $recursive = false): bool
    {
        return mkdir($path, $mode, $recursive);
    }

    public function filePutContents(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
    }

    public function dirname(string $path): string
    {
        return dirname($path);
    }
}
