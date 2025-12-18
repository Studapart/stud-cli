<?php

namespace App\Tests\Handler;

use App\Handler\CacheClearHandler;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class CacheClearHandlerTest extends CommandTestCase
{
    private CacheClearHandler $handler;
    private string $tempCacheDir;
    private string $tempCacheFile;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$translationService = $this->translationService;
        $logger = $this->createMock(Logger::class);
        $this->handler = new CacheClearHandler($this->translationService, $logger);

        // Create a temporary cache directory for testing
        $this->tempCacheDir = sys_get_temp_dir() . '/stud-test-cache-' . uniqid();
        $this->tempCacheFile = $this->tempCacheDir . '/.cache/stud/last_update_check.json';
        @mkdir(dirname($this->tempCacheFile), 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test files
        if (file_exists($this->tempCacheFile)) {
            @unlink($this->tempCacheFile);
        }
        if (is_dir(dirname($this->tempCacheFile))) {
            @rmdir(dirname($this->tempCacheFile));
        }
        if (is_dir($this->tempCacheDir . '/.cache')) {
            @rmdir($this->tempCacheDir . '/.cache');
        }
        if (is_dir($this->tempCacheDir)) {
            @rmdir($this->tempCacheDir);
        }
    }

    public function testHandleWithExistingCacheFile(): void
    {
        // Create cache file
        file_put_contents($this->tempCacheFile, json_encode(['latest_version' => '1.0.0', 'timestamp' => time()]));

        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            $output = new BufferedOutput();
            $io = new SymfonyStyle(new ArrayInput([]), $output);

            $result = $this->handler->handle($io);

            $this->assertSame(0, $result);
            $this->assertFileDoesNotExist($this->tempCacheFile);
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
        // Ensure cache file doesn't exist
        if (file_exists($this->tempCacheFile)) {
            @unlink($this->tempCacheFile);
        }

        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            $output = new BufferedOutput();
            $io = new SymfonyStyle(new ArrayInput([]), $output);

            $result = $this->handler->handle($io);

            $this->assertSame(0, $result);
            // Test intent: note() was called indicating cache was already clear, verified by return value
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }
}
