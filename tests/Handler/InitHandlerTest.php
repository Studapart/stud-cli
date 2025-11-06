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
        $this->handler = new InitHandler($this->fileSystem, '/tmp/config.yml');
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
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "repo_owner\n");
        fwrite($inputStream, "repo_name\n");
        fwrite($inputStream, "git_token\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

        $this->assertStringContainsString('Stud CLI Configuration Wizard', $output->fetch());
    }

    public function testHandleWithExistingConfig(): void
    {
        $existingConfig = [
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'existing@example.com',
            'JIRA_API_TOKEN' => 'existing_jira_token',
            'GIT_PROVIDER' => 'github',
            'GIT_REPO_OWNER' => 'existing_owner',
            'GIT_REPO_NAME' => 'existing_repo',
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
                    'JIRA_URL' => 'https://new-jira.example.com',
                    'JIRA_EMAIL' => 'new@example.com',
                    'JIRA_API_TOKEN' => 'existing_jira_token', // Should remain unchanged
                    'GIT_PROVIDER' => 'gitlab',
                    'GIT_REPO_OWNER' => 'new_owner',
                    'GIT_REPO_NAME' => 'new_repo',
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
        fwrite($inputStream, "https://new-jira.example.com/\n"); // New Jira URL
        fwrite($inputStream, "new@example.com\n"); // New Jira Email
        fwrite($inputStream, "\n"); // Keep existing Jira token
        fwrite($inputStream, "gitlab\n"); // Change Git provider
        fwrite($inputStream, "new_owner\n"); // New Repo Owner
        fwrite($inputStream, "new_repo\n"); // New Repo Name
        fwrite($inputStream, "\n"); // Keep existing Git token
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

        $this->assertStringContainsString('Configuration saved successfully!', $output->fetch());
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
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "repo_owner\n");
        fwrite($inputStream, "repo_name\n");
        fwrite($inputStream, "git_token\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

        $this->assertStringContainsString('Configuration saved successfully!', $output->fetch());
    }

    public function testHandleWithEmptyTokensPreservesExisting(): void
    {
        $existingConfig = [
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'existing@example.com',
            'JIRA_API_TOKEN' => 'existing_jira_token',
            'GIT_PROVIDER' => 'github',
            'GIT_REPO_OWNER' => 'existing_owner',
            'GIT_REPO_NAME' => 'existing_repo',
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
                    'JIRA_URL' => 'https://jira.example.com',
                    'JIRA_EMAIL' => 'existing@example.com',
                    'JIRA_API_TOKEN' => 'existing_jira_token',
                    'GIT_PROVIDER' => 'github',
                    'GIT_REPO_OWNER' => 'existing_owner',
                    'GIT_REPO_NAME' => 'existing_repo',
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
        fwrite($inputStream, "\n"); // Keep existing Jira URL
        fwrite($inputStream, "\n"); // Keep existing Jira Email
        fwrite($inputStream, "\n"); // Keep existing Jira token
        fwrite($inputStream, "\n"); // Keep existing Git provider
        fwrite($inputStream, "\n"); // Keep existing Repo Owner
        fwrite($inputStream, "\n"); // Keep existing Repo Name
        fwrite($inputStream, "\n"); // Keep existing Git token
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

        $this->assertStringContainsString('Configuration saved successfully!', $output->fetch());
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
                    'JIRA_URL' => 'https://jira.example.com',
                    'JIRA_EMAIL' => 'jira_email',
                    'JIRA_API_TOKEN' => 'jira_token',
                    'GIT_PROVIDER' => 'github',
                    'GIT_REPO_OWNER' => 'repo_owner',
                    'GIT_REPO_NAME' => 'repo_name',
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
        fwrite($inputStream, "https://jira.example.com/\n"); // Jira URL with trailing slash
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "repo_owner\n");
        fwrite($inputStream, "repo_name\n");
        fwrite($inputStream, "git_token\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

        $this->assertStringContainsString('Configuration saved successfully!', $output->fetch());
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
                    'JIRA_URL' => 'jira_url',
                    'JIRA_EMAIL' => 'jira_email',
                    'JIRA_API_TOKEN' => 'jira_token',
                    'GIT_PROVIDER' => 'gitlab',
                    'GIT_REPO_OWNER' => 'repo_owner',
                    'GIT_REPO_NAME' => 'repo_name',
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
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "gitlab\n"); // Select Gitlab
        fwrite($inputStream, "repo_owner\n");
        fwrite($inputStream, "repo_name\n");
        fwrite($inputStream, "git_token\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

        $this->assertStringContainsString('Configuration saved successfully!', $output->fetch());
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
                    'JIRA_URL' => 'jira_url',
                    'JIRA_EMAIL' => 'jira_email',
                    'JIRA_API_TOKEN' => 'jira_token',
                    'GIT_PROVIDER' => 'github',
                    'GIT_REPO_OWNER' => 'repo_owner',
                    'GIT_REPO_NAME' => 'repo_name',
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
        fwrite($inputStream, "jira_url\n");
        fwrite($inputStream, "jira_email\n");
        fwrite($inputStream, "jira_token\n");
        fwrite($inputStream, "github\n");
        fwrite($inputStream, "repo_owner\n");
        fwrite($inputStream, "repo_name\n");
        fwrite($inputStream, "git_token\n");
        rewind($inputStream);

        $input->setStream($inputStream);
        $io = new SymfonyStyle($input, $output);

        $this->handler->handle($io);

        $this->assertStringContainsString('Configuration saved successfully!', $output->fetch());
    }
}
