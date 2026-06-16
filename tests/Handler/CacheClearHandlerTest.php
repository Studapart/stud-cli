<?php

namespace App\Tests\Handler;

use App\Handler\CacheClearHandler;
use App\Service\FileSystem;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

class CacheClearHandlerTest extends CommandTestCase
{
    private CacheClearHandler $handler;
    private FileSystem $fileSystem;
    private FlysystemFilesystem $flysystem;
    private string $tempCacheDir;
    private string $tempCacheFile;

    protected function setUp(): void
    {
        parent::setUp();

        $adapter = new InMemoryFilesystemAdapter();
        $this->flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($this->flysystem);

        TestKernel::$translationService = $this->translationService;
        $this->handler = new CacheClearHandler($this->translationService, $this->fileSystem);

        $this->tempCacheDir = '/test/home';
        $this->tempCacheFile = $this->tempCacheDir . '/.cache/stud/last_update_check.json';

        $this->flysystem->createDirectory($this->tempCacheDir . '/.cache/stud');
    }

    public function testHandleWithExistingCacheFile(): void
    {
        $this->flysystem->write($this->tempCacheFile, json_encode(['latest_version' => '1.0.0', 'timestamp' => time()]));

        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            $result = $this->handler->handle();

            $this->assertTrue($result->isSuccess());
            $this->assertFalse($this->fileSystem->fileExists($this->tempCacheFile));
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testHandleWithNonExistentCacheFile(): void
    {
        if ($this->flysystem->fileExists($this->tempCacheFile)) {
            $this->flysystem->delete($this->tempCacheFile);
        }

        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            $result = $this->handler->handle();

            $this->assertTrue($result->isSuccess());
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testHandleWithDeleteException(): void
    {
        $this->flysystem->write($this->tempCacheFile, json_encode(['latest_version' => '1.0.0', 'timestamp' => time()]));

        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        $mockFileSystem = $this->createMock(FileSystem::class);
        $mockFileSystem->method('fileExists')
            ->with($this->tempCacheFile)
            ->willReturn(true);
        $mockFileSystem->method('delete')
            ->with($this->tempCacheFile)
            ->willThrowException(new \RuntimeException('Delete failed'));

        $handler = new CacheClearHandler($this->translationService, $mockFileSystem);

        try {
            $result = $handler->handle();

            $this->assertFalse($result->isSuccess());
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testConstructorAcceptsFileSystemDirectly(): void
    {
        $handler = new CacheClearHandler($this->translationService, $this->fileSystem);

        $this->assertInstanceOf(CacheClearHandler::class, $handler);
    }
}
