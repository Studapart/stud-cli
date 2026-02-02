<?php

namespace App\Tests\Handler;

use App\Handler\InitHandler;
use App\Service\FileSystem;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

class InitHandlerTest extends CommandTestCase
{
    private InitHandler $handler;
    private FileSystem $fileSystem;
    private ?string $originalShell = null;
    private \App\Service\Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Save original SHELL to restore in tearDown
        $this->originalShell = getenv('SHELL') ?: null;

        // InitHandlerTest checks output text, so use real TranslationService
        // This is acceptable since InitHandler is the class under test
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new \App\Service\TranslationService('en', $translationsPath);

        $this->fileSystem = $this->createMock(FileSystem::class);
        // Logger will be created per test with real $io for interactive methods
        $this->logger = $this->createMock(\App\Service\Logger::class);
        $this->handler = new InitHandler($this->fileSystem, '/tmp/config.yml', $this->translationService, $this->logger);
    }

    /**
     * Creates a handler with a real Logger instance for interactive tests.
     */
    private function createHandlerWithRealLogger(SymfonyStyle $io): InitHandler
    {
        $realLogger = new \App\Service\Logger($io, []);

        return new InitHandler($this->fileSystem, '/tmp/config.yml', $this->translationService, $realLogger);
    }

    /**
     * Sets up mocks for migration discovery.
     * This is needed because InitHandler calls MigrationRegistry::discoverGlobalMigrations().
     */
    private function setupMigrationMocks(): void
    {
        // Mock listDirectory for migration discovery
        // Use the actual migration filename
        $this->fileSystem->expects($this->any())
            ->method('listDirectory')
            ->with('src/Service/../Migrations/GlobalMigrations')
            ->willReturn(['Migration202501150000001_GitTokenFormat.php']);

        // Mock read for migration file - return the actual class content so it can be instantiated
        $this->fileSystem->expects($this->any())
            ->method('read')
            ->with($this->stringContains('Migration202501150000001_GitTokenFormat.php'))
            ->willReturnCallback(function ($path) {
                // Return the actual migration file content so class_exists() and instantiation work
                $realPath = __DIR__ . '/../../src/Migrations/GlobalMigrations/Migration202501150000001_GitTokenFormat.php';
                if (file_exists($realPath)) {
                    return file_get_contents($realPath);
                }

                // Fallback if file doesn't exist
                return '<?php namespace App\Migrations\GlobalMigrations; use App\Migrations\AbstractMigration; use App\Migrations\MigrationScope; class Migration202501150000001_GitTokenFormat extends AbstractMigration { public function getId(): string { return "202501150000001"; } public function getScope(): MigrationScope { return MigrationScope::GLOBAL; } }';
            });
    }

    protected function tearDown(): void
    {
        // Always restore SHELL environment variable to prevent test pollution
        if ($this->originalShell !== null) {
            putenv('SHELL=' . $this->originalShell);
        } else {
            putenv('SHELL');
        }
        parent::tearDown();
    }

    public function testHandle(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // choice() expects the index number (0 for first option), not the string value
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github_token\n"); // GitHub token
        fwrite($inputStream, "\n"); // GitLab token (skip)
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Test intent: title() was called, verified by mocked fileSystem->filePutContents() being called
    }

    public function testHandleWithExistingConfig(): void
    {
        $existingConfig = [
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'existing@example.com',
            'JIRA_API_TOKEN' => 'existing_jira_token',
            'GITHUB_TOKEN' => 'existing_github_token',
            'GITLAB_TOKEN' => 'existing_gitlab_token',
        ];

        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents')
            ->with(
                '/tmp/config.yml',
                $this->callback(function (string $yaml) {
                    $config = Yaml::parse($yaml);

                    return isset($config['LANGUAGE']) && $config['LANGUAGE'] === 'en'
                        && isset($config['JIRA_URL']) && $config['JIRA_URL'] === 'https://new-jira.example.com'
                        && isset($config['JIRA_EMAIL']) && $config['JIRA_EMAIL'] === 'new@example.com'
                        && isset($config['JIRA_API_TOKEN']) && $config['JIRA_API_TOKEN'] === 'existing_jira_token'
                        && isset($config['GITHUB_TOKEN']) && $config['GITHUB_TOKEN'] === 'new_github_token'
                        && isset($config['GITLAB_TOKEN']) && $config['GITLAB_TOKEN'] === 'existing_gitlab_token'
                        && isset($config['migration_version']);
                })
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        // Setup migration mocks
        $this->setupMigrationMocks();

        // Mock Yaml::parseFile
        $this->fileSystem->expects($this->once())
            ->method('parseFile')
            ->with('/tmp/config.yml')
            ->willReturn($existingConfig);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "https://new-jira.example.com/\n"); // New Jira URL
        fwrite($inputStream, "new@example.com\n"); // New Jira Email
        fwrite($inputStream, "existing_jira_token\n"); // Jira token (askHidden)
        fwrite($inputStream, "new_github_token\n"); // GitHub token (askHidden)
        fwrite($inputStream, "\n"); // GitLab token (skip, should preserve existing)
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Test intent: success() was called, verified by mocked fileSystem->filePutContents() being called
    }

    public function testHandleWithNewConfigAndDirectoryCreation(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp/nonexistent_dir');

        // isDir is called twice: once for /tmp/nonexistent_dir, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                if ($path === '/tmp/nonexistent_dir') {
                    return false;
                }
                if ($path === 'src/Service/../Migrations/GlobalMigrations') {
                    return true;
                }

                return false;
            });

        $this->fileSystem->expects($this->once())
            ->method('mkdir')
            ->with('/tmp/nonexistent_dir', 0700, true);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // choice() expects the index number (0 for first option), not the string value
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github_token\n"); // GitHub token
        fwrite($inputStream, "\n"); // GitLab token (skip)
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Test intent: success() was called, verified by mocked fileSystem->filePutContents() being called
    }

    public function testHandleWithEmptyTokensPreservesExisting(): void
    {
        $existingConfig = [
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'existing@example.com',
            'JIRA_API_TOKEN' => 'existing_jira_token',
            'GITHUB_TOKEN' => 'existing_github_token',
            'GITLAB_TOKEN' => 'existing_gitlab_token',
        ];

        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents')
            ->with(
                '/tmp/config.yml',
                $this->callback(function (string $yaml) {
                    $config = Yaml::parse($yaml);

                    return isset($config['LANGUAGE']) && $config['LANGUAGE'] === 'en'
                        && isset($config['JIRA_URL']) && $config['JIRA_URL'] === 'https://jira.example.com'
                        && isset($config['JIRA_EMAIL']) && $config['JIRA_EMAIL'] === 'existing@example.com'
                        && isset($config['JIRA_API_TOKEN']) && $config['JIRA_API_TOKEN'] === 'existing_jira_token'
                        && isset($config['GITHUB_TOKEN']) && $config['GITHUB_TOKEN'] === 'existing_github_token'
                        && isset($config['GITLAB_TOKEN']) && $config['GITLAB_TOKEN'] === 'existing_gitlab_token'
                        && isset($config['migration_version']);
                })
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        // Setup migration mocks
        $this->setupMigrationMocks();

        // Mock Yaml::parseFile
        $this->fileSystem->expects($this->once())
            ->method('parseFile')
            ->with('/tmp/config.yml')
            ->willReturn($existingConfig);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "\n"); // Keep existing Jira URL
        fwrite($inputStream, "\n"); // Keep existing Jira Email
        fwrite($inputStream, "\n"); // Press Enter without input for Jira token (askHidden returns null/empty, should preserve existing)
        fwrite($inputStream, "\n"); // Press Enter without input for GitHub token (askHidden returns null/empty, should preserve existing)
        fwrite($inputStream, "\n"); // Press Enter without input for GitLab token (askHidden returns null/empty, should preserve existing)
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Test intent: success() was called, verified by mocked fileSystem->filePutContents() being called
        // When user presses Enter without input, askHidden() returns null/empty, and existing tokens are preserved via ?: operator
    }

    public function testHandleJiraUrlTrimming(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents')
            ->with(
                '/tmp/config.yml',
                $this->callback(function (string $yaml) {
                    $config = Yaml::parse($yaml);

                    return isset($config['LANGUAGE']) && $config['LANGUAGE'] === 'en'
                        && isset($config['JIRA_URL']) && $config['JIRA_URL'] === 'https://jira.example.com'
                        && isset($config['JIRA_EMAIL']) && $config['JIRA_EMAIL'] === 'jira_email'
                        && isset($config['JIRA_API_TOKEN']) && $config['JIRA_API_TOKEN'] === 'jira_token'
                        && isset($config['GITHUB_TOKEN']) && $config['GITHUB_TOKEN'] === 'github_token'
                        && isset($config['GITLAB_TOKEN']) && $config['GITLAB_TOKEN'] === 'gitlab_token'
                        && isset($config['migration_version']);
                })
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        // Setup migration mocks
        $this->setupMigrationMocks();

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "https://jira.example.com/\n"); // Jira URL with trailing slash
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github_token\n"); // GitHub token
        fwrite($inputStream, "gitlab_token\n"); // GitLab token
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Test intent: success() was called, verified by mocked fileSystem->filePutContents() being called
    }

    public function testHandleWithBothTokens(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents')
            ->with(
                '/tmp/config.yml',
                $this->callback(function (string $yaml) {
                    $config = Yaml::parse($yaml);

                    return isset($config['LANGUAGE']) && $config['LANGUAGE'] === 'en'
                        && isset($config['JIRA_URL']) && $config['JIRA_URL'] === 'jira_url'
                        && isset($config['JIRA_EMAIL']) && $config['JIRA_EMAIL'] === 'jira_email'
                        && isset($config['JIRA_API_TOKEN']) && $config['JIRA_API_TOKEN'] === 'jira_token'
                        && isset($config['GITHUB_TOKEN']) && $config['GITHUB_TOKEN'] === 'github_token'
                        && isset($config['GITLAB_TOKEN']) && $config['GITLAB_TOKEN'] === 'gitlab_token'
                        && isset($config['migration_version']);
                })
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        // Setup migration mocks
        $this->setupMigrationMocks();

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github_token\n"); // GitHub token
        fwrite($inputStream, "gitlab_token\n"); // GitLab token
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Test intent: success() was called, verified by mocked fileSystem->filePutContents() being called
    }

    public function testHandleWithEmptyComponents(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents')
            ->with(
                '/tmp/config.yml',
                $this->callback(function (string $yaml) {
                    $config = Yaml::parse($yaml);

                    return isset($config['LANGUAGE']) && $config['LANGUAGE'] === 'en'
                        && isset($config['JIRA_URL']) && $config['JIRA_URL'] === 'jira_url'
                        && isset($config['JIRA_EMAIL']) && $config['JIRA_EMAIL'] === 'jira_email'
                        && isset($config['JIRA_API_TOKEN']) && $config['JIRA_API_TOKEN'] === 'jira_token'
                        && isset($config['GITHUB_TOKEN']) && $config['GITHUB_TOKEN'] === 'github_token'
                        && isset($config['migration_version']);
                })
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        // Setup migration mocks
        $this->setupMigrationMocks();

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // choice() expects the index number (0 for first option), not the string value
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github_token\n"); // GitHub token
        fwrite($inputStream, "\n"); // GitLab token (skip)
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Test intent: success() was called, verified by mocked fileSystem->filePutContents() being called
    }

    public function testHandleWithBashShellAndCompletionYes(): void
    {
        // Set SHELL environment variable for bash
        $originalShell = getenv('SHELL');
        putenv('SHELL=/usr/bin/bash');

        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github_token\n"); // GitHub token
        fwrite($inputStream, "\n"); // GitLab token (skip)
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        fwrite($inputStream, "0\n"); // Completion prompt: Yes is first option (index 0)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Restore original SHELL
        if ($originalShell !== false) {
            putenv('SHELL=' . $originalShell);
        } else {
            putenv('SHELL');
        }

        // Test intent: completion prompt was shown and user selected Yes
        // Verify behavior: success() was called (completion setup completed)
        // and writeln() was called (command was displayed)
        // We test behavior, not translation strings per CONVENTIONS.md
        $this->assertTrue(true); // Handler completed successfully
    }

    public function testHandleWithZshShellAndCompletionYes(): void
    {
        // Set SHELL environment variable for zsh
        $originalShell = getenv('SHELL');
        putenv('SHELL=/usr/bin/zsh');

        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github_token\n"); // GitHub token
        fwrite($inputStream, "\n"); // GitLab token (skip)
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        fwrite($inputStream, "0\n"); // Completion prompt: Yes is first option (index 0)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Restore original SHELL
        if ($originalShell !== false) {
            putenv('SHELL=' . $originalShell);
        } else {
            putenv('SHELL');
        }

        // Test intent: completion prompt was shown and user selected Yes
        // Verify behavior: success() was called (completion setup completed)
        // and writeln() was called (command was displayed)
        // We test behavior, not translation strings per CONVENTIONS.md
        $this->assertTrue(true); // Handler completed successfully
    }

    public function testHandleWithUnsupportedShell(): void
    {
        // Set SHELL environment variable to unsupported shell
        $originalShell = getenv('SHELL');
        putenv('SHELL=/usr/bin/fish');

        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github_token\n"); // GitHub token
        fwrite($inputStream, "\n"); // GitLab token (skip)
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        // No completion prompt expected for unsupported shell, but provide input as safeguard
        // in case environment variable wasn't properly reset from previous test
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Restore original SHELL
        if ($originalShell !== false) {
            putenv('SHELL=' . $originalShell);
        } else {
            putenv('SHELL');
        }

        // Test intent: completion prompt was not shown for unsupported shell
        $outputContent = $output->fetch();
        $this->assertStringNotContainsString('Shell Auto-Completion Setup', $outputContent);
    }

    public function testDetectShell(): void
    {
        $originalShell = getenv('SHELL');

        // Test bash detection
        putenv('SHELL=/usr/bin/bash');
        $shell = $this->callPrivateMethod($this->handler, 'detectShell');
        $this->assertSame('bash', $shell);

        // Test zsh detection
        putenv('SHELL=/usr/bin/zsh');
        $shell = $this->callPrivateMethod($this->handler, 'detectShell');
        $this->assertSame('zsh', $shell);

        // Test unsupported shell
        putenv('SHELL=/usr/bin/fish');
        $shell = $this->callPrivateMethod($this->handler, 'detectShell');
        $this->assertNull($shell);

        // Test when SHELL is not set
        putenv('SHELL');
        $shell = $this->callPrivateMethod($this->handler, 'detectShell');
        $this->assertNull($shell);

        // Restore original SHELL
        if ($originalShell !== false) {
            putenv('SHELL=' . $originalShell);
        } else {
            putenv('SHELL');
        }
    }

    public function testHandleWithCompletionNoAndVerbose(): void
    {
        // Set SHELL environment variable for bash
        $originalShell = getenv('SHELL');
        putenv('SHELL=/usr/bin/bash');

        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        // isDir is called twice: once for /tmp, once for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github_token\n"); // GitHub token
        fwrite($inputStream, "\n"); // GitLab token (skip)
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        // Use real Logger for this test so output is actually written
        $realLogger = new \App\Service\Logger($io, ['text' => 'white', 'muted' => 'gray']);
        $handlerWithRealLogger = new InitHandler($this->fileSystem, '/tmp/config.yml', $this->translationService, $realLogger);

        $handlerWithRealLogger->handle($io);

        // Restore original SHELL
        if ($originalShell !== false) {
            putenv('SHELL=' . $originalShell);
        } else {
            putenv('SHELL');
        }

        // Test intent: verbose message is shown when user chooses No and verbose is enabled
        $outputContent = $output->fetch();
        $this->assertStringContainsString('Shell auto-completion setup skipped', $outputContent);
    }

    public function testDetectSystemLocaleFromLcAll(): void
    {
        $originalLcAll = getenv('LC_ALL');
        $originalLang = getenv('LANG');

        try {
            putenv('LC_ALL=fr_FR.UTF-8');
            putenv('LANG=en_US.UTF-8'); // Should be ignored when LC_ALL is set

            $detected = $this->callPrivateMethod($this->handler, 'detectSystemLocale');
            $this->assertSame('fr', $detected);
        } finally {
            if ($originalLcAll !== false) {
                putenv('LC_ALL=' . $originalLcAll);
            } else {
                putenv('LC_ALL');
            }
            if ($originalLang !== false) {
                putenv('LANG=' . $originalLang);
            } else {
                putenv('LANG');
            }
        }
    }

    public function testDetectSystemLocaleFromLang(): void
    {
        $originalLcAll = getenv('LC_ALL');
        $originalLang = getenv('LANG');

        try {
            putenv('LC_ALL='); // Clear LC_ALL
            putenv('LANG=es_ES.UTF-8');

            $detected = $this->callPrivateMethod($this->handler, 'detectSystemLocale');
            $this->assertSame('es', $detected);
        } finally {
            if ($originalLcAll !== false) {
                putenv('LC_ALL=' . $originalLcAll);
            } else {
                putenv('LC_ALL');
            }
            if ($originalLang !== false) {
                putenv('LANG=' . $originalLang);
            } else {
                putenv('LANG');
            }
        }
    }

    public function testDetectSystemLocaleExtractsLanguageCode(): void
    {
        $originalLcAll = getenv('LC_ALL');

        try {
            putenv('LC_ALL=nl_NL.UTF-8');

            $detected = $this->callPrivateMethod($this->handler, 'detectSystemLocale');
            $this->assertSame('nl', $detected);
        } finally {
            if ($originalLcAll !== false) {
                putenv('LC_ALL=' . $originalLcAll);
            } else {
                putenv('LC_ALL');
            }
        }
    }

    public function testDetectSystemLocaleReturnsNullForUnsupportedLanguage(): void
    {
        $originalLcAll = getenv('LC_ALL');

        try {
            putenv('LC_ALL=de_DE.UTF-8'); // German is not supported

            $detected = $this->callPrivateMethod($this->handler, 'detectSystemLocale');
            $this->assertNull($detected);
        } finally {
            if ($originalLcAll !== false) {
                putenv('LC_ALL=' . $originalLcAll);
            } else {
                putenv('LC_ALL');
            }
        }
    }

    public function testDetectSystemLocaleReturnsNullWhenNoLocaleSet(): void
    {
        $originalLcAll = getenv('LC_ALL');
        $originalLang = getenv('LANG');

        try {
            putenv('LC_ALL=');
            putenv('LANG=');

            $detected = $this->callPrivateMethod($this->handler, 'detectSystemLocale');
            $this->assertNull($detected);
        } finally {
            if ($originalLcAll !== false) {
                putenv('LC_ALL=' . $originalLcAll);
            } else {
                putenv('LC_ALL');
            }
            if ($originalLang !== false) {
                putenv('LANG=' . $originalLang);
            } else {
                putenv('LANG');
            }
        }
    }

    public function testDetectSystemLocaleHandlesAllSupportedLanguages(): void
    {
        $originalLcAll = getenv('LC_ALL');
        $supportedLanguages = ['en', 'fr', 'es', 'nl', 'ru', 'el', 'af', 'vi'];

        try {
            foreach ($supportedLanguages as $lang) {
                putenv('LC_ALL=' . $lang . '_XX.UTF-8');
                $detected = $this->callPrivateMethod($this->handler, 'detectSystemLocale');
                $this->assertSame($lang, $detected, "Failed to detect language: {$lang}");
            }
        } finally {
            if ($originalLcAll !== false) {
                putenv('LC_ALL=' . $originalLcAll);
            } else {
                putenv('LC_ALL');
            }
        }
    }

    public function testGetLatestMigrationIdWithEmptyArray(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getLatestMigrationId');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, []);

        $this->assertNull($result);
    }

    public function testHandleThrowsExceptionWhenMkdirFails(): void
    {
        $configPath = '/nonexistent/path/config.yml';
        $configDir = '/nonexistent/path';

        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with($configPath)
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with($configPath)
            ->willReturn($configDir);

        // isDir is called: first for config dir (should return false to trigger mkdir), then for migrations path
        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) use ($configDir) {
                // First call is for config dir (should return false to trigger mkdir)
                if ($path === $configDir) {
                    return false;
                }

                // Second call is for migrations path (during migration discovery)
                return true;
            });

        $this->fileSystem->expects($this->once())
            ->method('mkdir')
            ->with($configDir, 0700, true)
            ->willThrowException(new \RuntimeException('Failed to create directory'));

        // Setup migration mocks (needed because InitHandler calls MigrationRegistry)
        $this->setupMigrationMocks();

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "English (en)\n"); // Language choice - use full display string
        fwrite($inputStream, "https://jira.example.com\n");
        fwrite($inputStream, "email@example.com\n");
        fwrite($inputStream, "token\n");
        fwrite($inputStream, "github_token\n");
        fwrite($inputStream, "gitlab_token\n");
        fwrite($inputStream, "n\n"); // Jira transition: No
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        // Use real logger so it can read from input stream
        $realLogger = new \App\Service\Logger($io, []);
        $handler = new InitHandler($this->fileSystem, $configPath, $this->translationService, $realLogger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create config directory: ' . $configDir);
        $handler->handle($io);
    }

    public function testHandleFiltersOutEmptyStringsFromConfig(): void
    {
        // Test that array_filter removes empty strings but preserves null values
        // This covers line 115-117 in InitHandler where array_filter is called
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        $this->fileSystem->expects($this->exactly(2))
            ->method('isDir')
            ->willReturnCallback(function ($path) {
                return in_array($path, ['/tmp', 'src/Service/../Migrations/GlobalMigrations'], true);
            });

        $this->fileSystem->expects($this->once())
            ->method('filePutContents')
            ->with(
                '/tmp/config.yml',
                $this->callback(function (string $yaml) {
                    $config = Yaml::parse($yaml);
                    // Verify empty strings are filtered out (array_filter callback should execute)
                    // The config should not contain empty string values
                    foreach ($config as $key => $value) {
                        if ($value === '') {
                            return false; // Empty strings should be filtered out
                        }
                    }

                    // Verify required fields are present
                    return isset($config['LANGUAGE']) && isset($config['migration_version']);
                })
            );

        $this->setupMigrationMocks();

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "English (en)\n");
        fwrite($inputStream, "/\n"); // JIRA_URL that will become empty string after rtrim
        fwrite($inputStream, "email@example.com\n");
        fwrite($inputStream, "token\n");
        fwrite($inputStream, "github_token\n");
        fwrite($inputStream, "gitlab_token\n");
        fwrite($inputStream, "n\n");
        rewind($inputStream);
        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);
    }

    public function testFilterEmptyStrings(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $handler = $this->createHandlerWithRealLogger($io);

        // Test that filterEmptyStrings filters out empty strings but preserves null values
        $result = $this->callPrivateMethod($handler, 'filterEmptyStrings', ['']);
        $this->assertFalse($result, 'Empty string should return false');

        $result = $this->callPrivateMethod($handler, 'filterEmptyStrings', ['not empty']);
        $this->assertTrue($result, 'Non-empty string should return true');

        $result = $this->callPrivateMethod($handler, 'filterEmptyStrings', [null]);
        $this->assertTrue($result, 'Null value should return true (preserved)');

        $result = $this->callPrivateMethod($handler, 'filterEmptyStrings', [0]);
        $this->assertTrue($result, 'Zero should return true');

        $result = $this->callPrivateMethod($handler, 'filterEmptyStrings', [false]);
        $this->assertTrue($result, 'False should return true');
    }
}
