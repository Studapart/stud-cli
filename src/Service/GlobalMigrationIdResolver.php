<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Resolves the latest global migration id from migration filenames without instantiating migrations.
 */
class GlobalMigrationIdResolver
{
    private const GLOBAL_MIGRATIONS_PATH = __DIR__ . '/../Migrations/GlobalMigrations';

    public function __construct(private readonly FileSystem $fileSystem)
    {
    }

    public function resolveLatestId(): ?string
    {
        $path = $this->resolvePath(self::GLOBAL_MIGRATIONS_PATH);
        if (! $this->fileSystem->isDir($path)) {
            return null;
        }

        try {
            $files = $this->fileSystem->listDirectory($path);
        } catch (\RuntimeException) {
            return null;
        }

        $latestId = null;
        foreach ($files as $file) {
            if (preg_match('/Migration(\d+)_/', basename($file), $matches) !== 1) {
                continue;
            }

            $id = $matches[1];
            if ($latestId === null || strcmp($id, $latestId) > 0) {
                $latestId = $id;
            }
        }

        return $latestId;
    }

    protected function resolvePath(string $path): string
    {
        $cwd = $this->readWorkingDirectory();
        if ($cwd === false) {
            return $path;
        }

        if (str_starts_with($path, '/') && str_starts_with($path, $cwd)) {
            $relative = ltrim(str_replace($cwd, '', $path), '/');

            return $relative !== '' ? $relative : '.';
        }

        return $path;
    }

    protected function readWorkingDirectory(): string|false
    {
        return getcwd();
    }
}
