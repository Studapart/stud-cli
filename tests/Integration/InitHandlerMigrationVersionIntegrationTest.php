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

/**
 * Integration tests for InitHandler migration_version preservation.
 *
 * These tests use real filesystem operations to test the edge case where
 * an existing config file already has a migration_version that should be preserved.
 */
#[Group('integration')]
class InitHandlerMigrationVersionIntegrationTest extends TestCase
{
    private string $tempDir;
    private string $configPath;
    private FileSystem $fileSystem;
    private TranslationService $translationService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary directory for config
        $this->tempDir = sys_get_temp_dir() . '/stud-cli-test-' . uniqid();
        $this->configPath = $this->tempDir . '/config.yml';

        mkdir($this->tempDir, 0755, true);

        // Create real filesystem pointing to temp directory
        $adapter = new LocalFilesystemAdapter($this->tempDir);
        $flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($flysystem);

        // Use real translation service
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new TranslationService('en', $translationsPath);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    /**
     * Recursively removes a directory and its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Integration test for preserving migration_version from existing config.
     * Tests that when InitHandler is called with an existing config that has
     * migration_version set, it preserves that value instead of setting it to the latest.
     */
    public function testInitHandlerPreservesMigrationVersionFromExistingConfig(): void
    {
        // Set a timeout for this test to prevent hanging
        set_time_limit(30);

        // Create existing config with migration_version already set
        $existingMigrationVersion = '202501150000001';
        $existingConfig = [
            'LANGUAGE' => 'en',
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'existing@example.com',
            'JIRA_API_TOKEN' => 'existing_token',
            'GITHUB_TOKEN' => 'github_token',
            'GITLAB_TOKEN' => 'gitlab_token',
            'migration_version' => $existingMigrationVersion,
        ];

        // Write existing config to filesystem
        $this->fileSystem->dumpFile('config.yml', $existingConfig);

        // Create input stream with responses for all prompts BEFORE creating the handler
        $inputStream = fopen('php://memory', 'r+');
        // Need to provide the exact choice string format that choice() expects
        // The choice() method expects the full display string, not just the index
        fwrite($inputStream, "English (en)\n"); // Language choice - use the full display string
        fwrite($inputStream, "https://jira.example.com\n");
        fwrite($inputStream, "existing@example.com\n");
        fwrite($inputStream, "existing_token\n");
        fwrite($inputStream, "github_token\n");
        fwrite($inputStream, "gitlab_token\n");
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        rewind($inputStream);

        // Create input and output
        $input = new ArrayInput([]);
        $input->setStream($inputStream);
        $output = new BufferedOutput();
        $io = new SymfonyStyle($input, $output);

        // Create handler with the IO that has the input stream
        $logger = new Logger($io, []);
        $handler = new InitHandler($this->fileSystem, 'config.yml', $this->translationService, $logger);

        // Execute handler
        $handler->handle($io);

        // Verify config was updated but migration_version was preserved
        $updatedConfig = $this->fileSystem->parseFile('config.yml');
        $this->assertSame($existingMigrationVersion, $updatedConfig['migration_version'], 'migration_version should be preserved from existing config');
        $this->assertSame('en', $updatedConfig['LANGUAGE']);
        $this->assertSame('https://jira.example.com', $updatedConfig['JIRA_URL']);
    }
}
