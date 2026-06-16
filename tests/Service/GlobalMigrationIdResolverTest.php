<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FileSystem;
use App\Service\GlobalMigrationIdResolver;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GlobalMigrationIdResolverTest extends TestCase
{
    public function testResolveLatestIdFromRealGlobalMigrations(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $adapter = new LocalFilesystemAdapter($projectRoot);
        $flysystem = new FlysystemFilesystem($adapter);
        $fileSystem = new FileSystem($flysystem);

        $resolver = new GlobalMigrationIdResolver($fileSystem);

        $this->assertSame('202501150000001', $resolver->resolveLatestId());
    }

    public function testResolveLatestIdReturnsNullWhenDirectoryMissing(): void
    {
        $adapter = new \League\Flysystem\InMemory\InMemoryFilesystemAdapter();
        $fileSystem = new FileSystem(new FlysystemFilesystem($adapter));

        $resolver = new GlobalMigrationIdResolver($fileSystem);

        $this->assertNull($resolver->resolveLatestId());
    }

    public function testResolveLatestIdReturnsNullWhenListDirectoryFails(): void
    {
        /** @var FileSystem&MockObject $fileSystem */
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->method('isDir')->willReturn(true);
        $fileSystem->method('listDirectory')->willThrowException(new \RuntimeException('unreadable'));

        $resolver = new GlobalMigrationIdResolver($fileSystem);

        $this->assertNull($resolver->resolveLatestId());
    }

    public function testResolveLatestIdIgnoresNonMigrationFiles(): void
    {
        $adapter = new \League\Flysystem\InMemory\InMemoryFilesystemAdapter();
        $fileSystem = new FileSystem(new FlysystemFilesystem($adapter));
        $fileSystem->write('src/Migrations/GlobalMigrations/README.md', '');
        $fileSystem->write('src/Migrations/GlobalMigrations/Migration002_Newer.php', '');
        $fileSystem->write('src/Migrations/GlobalMigrations/Migration001_Older.php', '');

        $resolver = new GlobalMigrationIdResolver($fileSystem);

        $this->assertSame('002', $resolver->resolveLatestId());
    }

    public function testResolvePathReturnsAbsolutePathWhenOutsideWorkingDirectory(): void
    {
        $fileSystem = new FileSystem(new FlysystemFilesystem(new \League\Flysystem\InMemory\InMemoryFilesystemAdapter()));
        $resolver = new class ($fileSystem) extends GlobalMigrationIdResolver {
            public function resolvePathForTest(string $path): string
            {
                return $this->resolvePath($path);
            }
        };
        $absolutePath = dirname(__DIR__, 2) . '/src/Migrations/GlobalMigrations';
        $previousDirectory = getcwd();
        $this->assertNotFalse($previousDirectory);

        chdir(sys_get_temp_dir());

        try {
            $this->assertSame($absolutePath, $resolver->resolvePathForTest($absolutePath));
        } finally {
            chdir($previousDirectory);
        }
    }

    public function testResolvePathReturnsInputWhenWorkingDirectoryUnavailable(): void
    {
        $fileSystem = new FileSystem(new FlysystemFilesystem(new \League\Flysystem\InMemory\InMemoryFilesystemAdapter()));
        $resolver = new class ($fileSystem) extends GlobalMigrationIdResolver {
            protected function readWorkingDirectory(): string|false
            {
                return false;
            }

            public function resolvePathForTest(string $path): string
            {
                return $this->resolvePath($path);
            }
        };
        $absolutePath = dirname(__DIR__, 2) . '/src/Migrations/GlobalMigrations';

        $this->assertSame($absolutePath, $resolver->resolvePathForTest($absolutePath));
    }
}
