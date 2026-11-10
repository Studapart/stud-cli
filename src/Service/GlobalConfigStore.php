<?php

declare(strict_types=1);

namespace App\Service;

final class GlobalConfigStore
{
    public function __construct(
        public readonly FileSystem $fileSystem,
        public readonly string $path,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        return $this->fileSystem->fileExists($this->path)
            ? $this->fileSystem->parseFile($this->path)
            : [];
    }

    public function write(string $contents): void
    {
        $configDir = $this->fileSystem->dirname($this->path);
        if (! $this->fileSystem->isDir($configDir)) {
            try {
                $this->fileSystem->mkdir($configDir, 0700, true);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException("Failed to create config directory: {$configDir}", 0, $e);
            }
        }

        $this->fileSystem->backupFileIfExists($this->path);
        $this->fileSystem->filePutContents($this->path, $contents);
    }
}
