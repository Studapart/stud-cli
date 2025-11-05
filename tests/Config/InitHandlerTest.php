<?php

namespace App\Tests\Config;

use App\Config\InitHandler;
use App\FileSystem\FileSystem;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

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
}
