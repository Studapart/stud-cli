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

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

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
        fwrite($inputStream, "0\n"); // Git provider: github is first option (index 0)
        fwrite($inputStream, "git_token\n");
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
            'GIT_PROVIDER' => 'github',
            'GIT_TOKEN' => 'existing_git_token',
        ];

        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents')
            ->with(
                '/tmp/config.yml',
                Yaml::dump([
                    'LANGUAGE' => 'en',
                    'JIRA_URL' => 'https://new-jira.example.com',
                    'JIRA_EMAIL' => 'new@example.com',
                    'JIRA_API_TOKEN' => 'existing_jira_token', // Should remain unchanged
                    'GIT_PROVIDER' => 'gitlab',
                    'GIT_TOKEN' => 'existing_git_token', // Should remain unchanged
                ])
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

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
        fwrite($inputStream, "1\n"); // Git provider: gitlab is second option (index 1)
        fwrite($inputStream, "existing_git_token\n"); // Git token (askHidden)
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

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp/nonexistent_dir')
            ->willReturn(false);

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
        fwrite($inputStream, "0\n"); // Git provider: github is first option (index 0)
        fwrite($inputStream, "git_token\n");
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
            'GIT_PROVIDER' => 'github',
            'GIT_TOKEN' => 'existing_git_token',
        ];

        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents')
            ->with(
                '/tmp/config.yml',
                Yaml::dump([
                    'LANGUAGE' => 'en',
                    'JIRA_URL' => 'https://jira.example.com',
                    'JIRA_EMAIL' => 'existing@example.com',
                    'JIRA_API_TOKEN' => 'existing_jira_token',
                    'GIT_PROVIDER' => 'github',
                    'GIT_TOKEN' => 'existing_git_token',
                ])
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

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
        fwrite($inputStream, "\n"); // Keep existing Git provider
        fwrite($inputStream, "\n"); // Press Enter without input for Git token (askHidden returns null/empty, should preserve existing)
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
                Yaml::dump([
                    'LANGUAGE' => 'en',
                    'JIRA_URL' => 'https://jira.example.com',
                    'JIRA_EMAIL' => 'jira_email',
                    'JIRA_API_TOKEN' => 'jira_token',
                    'GIT_PROVIDER' => 'github',
                    'GIT_TOKEN' => 'git_token',
                ])
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "https://jira.example.com/\n"); // Jira URL with trailing slash
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "0\n"); // Git provider: github is first option (index 0)
        fwrite($inputStream, "git_token\n");
        fwrite($inputStream, "n\n"); // Jira transition enabled: No
        fwrite($inputStream, "1\n"); // Completion prompt: No is second option (index 1)
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $handler = $this->createHandlerWithRealLogger($io);
        $handler->handle($io);

        // Test intent: success() was called, verified by mocked fileSystem->filePutContents() being called
    }

    public function testHandleWithGitlabProvider(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/tmp/config.yml')
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents')
            ->with(
                '/tmp/config.yml',
                Yaml::dump([
                    'LANGUAGE' => 'en',
                    'JIRA_URL' => 'jira_url',
                    'JIRA_EMAIL' => 'jira_email',
                    'JIRA_API_TOKEN' => 'jira_token',
                    'GIT_PROVIDER' => 'gitlab',
                    'GIT_TOKEN' => 'git_token',
                ])
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "1\n"); // Git provider: gitlab is second option (index 1)
        fwrite($inputStream, "git_token\n");
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
                Yaml::dump([
                    'LANGUAGE' => 'en',
                    'JIRA_URL' => 'jira_url',
                    'JIRA_EMAIL' => 'jira_email',
                    'JIRA_API_TOKEN' => 'jira_token',
                    'GIT_PROVIDER' => 'github',
                    'GIT_TOKEN' => 'git_token',
                ])
            );

        $this->fileSystem->expects($this->once())
            ->method('dirname')
            ->with('/tmp/config.yml')
            ->willReturn('/tmp');

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        // choice() expects the index number (0 for first option), not the string value
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "0\n"); // Git provider: github is first option (index 0)
        fwrite($inputStream, "git_token\n");
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

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "0\n"); // Git provider: github is first option (index 0)
        fwrite($inputStream, "git_token\n");
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
        $outputContent = $output->fetch();
        $this->assertStringContainsString('Great! To complete the installation', $outputContent);
        $this->assertStringContainsString('bash', $outputContent);
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

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "0\n"); // Git provider: github is first option (index 0)
        fwrite($inputStream, "git_token\n");
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
        $outputContent = $output->fetch();
        $this->assertStringContainsString('Great! To complete the installation', $outputContent);
        $this->assertStringContainsString('zsh', $outputContent);
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

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "0\n"); // Git provider: github is first option (index 0)
        fwrite($inputStream, "git_token\n");
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

        $this->fileSystem->expects($this->once())
            ->method('isDir')
            ->with('/tmp')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('filePutContents');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, "0\n"); // Language selection: English (en) is first option (index 0)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "0\n"); // Git provider: github is first option (index 0)
        fwrite($inputStream, "git_token\n");
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
}
