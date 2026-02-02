<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Handler\InitHandler;
use App\Service\FileSystem;
use App\Service\Logger;
use App\Service\TranslationService;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Integration test for InitHandler array_filter functionality.
 *
 * This test verifies that empty strings are filtered out from the config
 * while preserving null values. This covers the array_filter callback in InitHandler.
 */
#[Group('integration')]
class InitHandlerEmptyStringFilterIntegrationTest extends TestCase
{
    private string $tempDir;
    private string $configPath;
    private FileSystem $fileSystem;
    private TranslationService $translationService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for this test
        $this->tempDir = sys_get_temp_dir() . '/stud-cli-test-' . uniqid();
        mkdir($this->tempDir, 0700, true);

        // Create a local filesystem adapter with the temp directory as root
        $adapter = new LocalFilesystemAdapter($this->tempDir);
        $flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($flysystem);

        // Use real translation service
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new TranslationService('en', $translationsPath);

        $this->configPath = 'config.yml';
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testInitHandlerFiltersOutEmptyStringsFromConfig(): void
    {
        // This test verifies that when a JIRA_URL becomes empty after rtrim('/'),
        // it gets filtered out by array_filter. This covers line 115-117 in InitHandler.

        // Create input stream with responses
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "English (en)\n"); // Language choice
        fwrite($inputStream, "/\n"); // JIRA_URL that will become empty string after rtrim('/')
        fwrite($inputStream, "email@example.com\n");
        fwrite($inputStream, "token\n");
        fwrite($inputStream, "github_token\n");
        fwrite($inputStream, "gitlab_token\n");
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        rewind($inputStream);

        $input = new ArrayInput([]);
        $input->setStream($inputStream);
        $output = new BufferedOutput();
        $io = new SymfonyStyle($input, $output);

        $logger = new Logger($io, []);
        $handler = new InitHandler($this->fileSystem, $this->configPath, $this->translationService, $logger);

        // Execute handler
        $handler->handle($io);

        // Verify config was written and empty strings were filtered out
        $this->assertTrue($this->fileSystem->fileExists($this->configPath));

        $configContent = $this->fileSystem->read($this->configPath);
        $config = Yaml::parse($configContent);

        // Verify that empty strings were filtered out (array_filter callback executed)
        foreach ($config as $key => $value) {
            $this->assertNotSame('', $value, "Empty string found in config for key: {$key}");
        }

        // Verify required fields are present
        $this->assertArrayHasKey('LANGUAGE', $config);
        $this->assertArrayHasKey('JIRA_EMAIL', $config);

        // migration_version may or may not be present depending on migrations discovered
        // The important part is that empty strings are filtered out

        // Verify JIRA_URL is not present (it was empty and filtered out)
        $this->assertArrayNotHasKey('JIRA_URL', $config, 'Empty JIRA_URL should be filtered out');
    }
}
