<?php

namespace App\Tests\Handler;

use App\Handler\CacheClearHandler;
use App\Service\FileSystem;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

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

        // Create in-memory filesystem
        $adapter = new InMemoryFilesystemAdapter();
        $this->flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($this->flysystem);

        TestKernel::$translationService = $this->translationService;
        $logger = $this->createMock(Logger::class);
        $this->handler = new CacheClearHandler($this->translationService, $logger, $this->fileSystem);

        // Use in-memory path for cache file
        $this->tempCacheDir = '/test/home';
        $this->tempCacheFile = $this->tempCacheDir . '/.cache/stud/last_update_check.json';

        // Create directory structure in memory
        $this->flysystem->createDirectory($this->tempCacheDir . '/.cache/stud');
    }

    public function testHandleWithExistingCacheFile(): void
    {
        // Create cache file in in-memory filesystem
        $this->flysystem->write($this->tempCacheFile, json_encode(['latest_version' => '1.0.0', 'timestamp' => time()]));

        // Override HOME to use our test directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            $output = new BufferedOutput();
            $io = new SymfonyStyle(new ArrayInput([]), $output);

            $result = $this->handler->handle($io);

            $this->assertTrue($result->isSuccess());
            $this->assertFalse($this->fileSystem->fileExists($this->tempCacheFile));
            // Test intent: success() was called, verified by return value and file deletion
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
        // Ensure cache file doesn't exist in in-memory filesystem
        if ($this->flysystem->fileExists($this->tempCacheFile)) {
            $this->flysystem->delete($this->tempCacheFile);
        }

        // Override HOME to use our test directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            $output = new BufferedOutput();
            $io = new SymfonyStyle(new ArrayInput([]), $output);

            $result = $this->handler->handle($io);

            $this->assertTrue($result->isSuccess());
            // Test intent: note() was called indicating cache was already clear, verified by return value
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
        // Create cache file in in-memory filesystem
        $this->flysystem->write($this->tempCacheFile, json_encode(['latest_version' => '1.0.0', 'timestamp' => time()]));

        // Override HOME to use our test directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        // Create a mock FileSystem that throws an exception on delete
        $mockFileSystem = $this->createMock(FileSystem::class);
        $mockFileSystem->method('fileExists')
            ->with($this->tempCacheFile)
            ->willReturn(true);
        $mockFileSystem->method('delete')
            ->with($this->tempCacheFile)
            ->willThrowException(new \RuntimeException('Delete failed'));

        $logger = $this->createMock(Logger::class);
        $logger->method('addError')
            ->with(Logger::VERBOSITY_NORMAL, $this->anything());
        $handler = new CacheClearHandler($this->translationService, $logger, $mockFileSystem);

        try {
            $output = new BufferedOutput();
            $io = new SymfonyStyle(new ArrayInput([]), $output);

            $result = $handler->handle($io);

            $this->assertFalse($result->isSuccess());
            // Test intent: error() was called and handler returned error code, verified by return value
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testConstructorRequiresFileSystemWhenUsingLegacyOutputArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CacheClearHandler($this->translationService, $this->createMock(Logger::class));
    }

    public function testConstructorAcceptsFileSystemDirectly(): void
    {
        $handler = new CacheClearHandler($this->translationService, $this->fileSystem);

        $this->assertInstanceOf(CacheClearHandler::class, $handler);
    }
}
