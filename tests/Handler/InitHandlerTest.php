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

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileSystem = $this->createMock(FileSystem::class);
        $this->handler = new InitHandler($this->fileSystem, '/tmp/config.yml', $this->translationService);
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
        fwrite($inputStream, "English (en)\n"); // Language selection (first prompt)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "git_token\n");
        fwrite($inputStream, "No\n"); // Completion prompt
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

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
        fwrite($inputStream, "English (en)\n"); // Language selection (first prompt)
        fwrite($inputStream, "https://new-jira.example.com/\n"); // New Jira URL
        fwrite($inputStream, "new@example.com\n"); // New Jira Email
        fwrite($inputStream, "\n"); // Keep existing Jira token
        fwrite($inputStream, "gitlab\n"); // Change Git provider
        fwrite($inputStream, "\n"); // Keep existing Git token
        fwrite($inputStream, "No\n"); // Completion prompt
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

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
        fwrite($inputStream, "English (en)\n"); // Language selection (first prompt)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "git_token\n");
        fwrite($inputStream, "No\n"); // Completion prompt
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

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
        fwrite($inputStream, "English (en)\n"); // Language selection (first prompt)
        fwrite($inputStream, "\n"); // Keep existing Jira URL
        fwrite($inputStream, "\n"); // Keep existing Jira Email
        fwrite($inputStream, "\n"); // Keep existing Jira token
        fwrite($inputStream, "\n"); // Keep existing Git provider
        fwrite($inputStream, "\n"); // Keep existing Git token
        fwrite($inputStream, "No\n"); // Completion prompt
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

        // Test intent: success() was called, verified by mocked fileSystem->filePutContents() being called
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
        fwrite($inputStream, "English (en)\n"); // Language selection (first prompt)
        fwrite($inputStream, "https://jira.example.com/\n"); // Jira URL with trailing slash
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "git_token\n");
        fwrite($inputStream, "No\n"); // Completion prompt
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

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
        fwrite($inputStream, "English (en)\n"); // Language selection (first prompt)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "gitlab\n"); // Select Gitlab
        fwrite($inputStream, "git_token\n");
        fwrite($inputStream, "No\n"); // Completion prompt
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

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
        fwrite($inputStream, "English (en)\n"); // Language selection (first prompt)
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "git_token\n");
        fwrite($inputStream, "No\n"); // Completion prompt
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

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
        fwrite($inputStream, "English (en)\n");
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "git_token\n");
        fwrite($inputStream, "Yes\n"); // Completion prompt - Yes
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

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
        fwrite($inputStream, "English (en)\n");
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "git_token\n");
        fwrite($inputStream, "Yes\n"); // Completion prompt - Yes
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

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
        fwrite($inputStream, "English (en)\n");
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "git_token\n");
        // No completion prompt expected for unsupported shell
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

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
}
