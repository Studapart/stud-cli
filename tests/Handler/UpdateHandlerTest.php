<?php

namespace App\Tests\Handler;

use App\Handler\UpdateHandler;
use App\Service\GitRepository;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class UpdateHandlerTest extends CommandTestCase
{
    private UpdateHandler $handler;
    private HttpClientInterface&MockObject $httpClient;
    private string $tempBinaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->tempBinaryPath = sys_get_temp_dir() . '/stud-test.phar';
        
        // Create a temporary writable file for testing
        touch($this->tempBinaryPath);
        chmod($this->tempBinaryPath, 0644);

        $this->handler = new UpdateHandler(
            $this->gitRepository,
            '1.0.0',
            $this->tempBinaryPath,
            null, // gitToken
            $this->httpClient
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->tempBinaryPath);
        @unlink(sys_get_temp_dir() . '/stud.phar.new');
        parent::tearDown();
    }

    public function testHandleAlreadyOnLatestVersion(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $releaseData = [
            'tag_name' => 'v1.0.0',
            'assets' => [],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn($releaseData);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', '/repos/studapart/stud-cli/releases/latest')
            ->willReturn($response);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('You are already on the latest version', $outputText);
    }

    public function testHandleWithNoRepositoryInfo(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn(null);
        $this->gitRepository->method('getRepositoryName')->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Could not determine repository owner or name', $outputText);
    }

    public function testHandleWithNewerVersionAvailable(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $releaseData = [
            'tag_name' => 'v1.0.1',
            'assets' => [
                [
                    'name' => 'stud.phar',
                    'browser_download_url' => 'https://github.com/studapart/stud-cli/releases/download/v1.0.1/stud.phar',
                ],
            ],
        ];

        $releaseResponse = $this->createMock(ResponseInterface::class);
        $releaseResponse->method('getStatusCode')->willReturn(200);
        $releaseResponse->method('toArray')->willReturn($releaseData);

        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getContent')->willReturn('phar binary content');

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($releaseResponse, $downloadResponse) {
                if (str_contains($url, '/releases/latest')) {
                    return $releaseResponse;
                }
                return $downloadResponse;
            });

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('A new version (v1.0.1) is available', $outputText);
        $this->assertStringContainsString('Update complete! You are now on v1.0.1', $outputText);
        
        // Verify the binary was updated
        $this->assertStringEqualsFile($this->tempBinaryPath, 'phar binary content');
    }

    public function testHandleWithNonWritableBinary(): void
    {
        // Make file non-writable
        chmod($this->tempBinaryPath, 0444);

        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $releaseData = [
            'tag_name' => 'v1.0.1',
            'assets' => [
                [
                    'name' => 'stud.phar',
                    'browser_download_url' => 'https://github.com/studapart/stud-cli/releases/download/v1.0.1/stud.phar',
                ],
            ],
        ];

        $releaseResponse = $this->createMock(ResponseInterface::class);
        $releaseResponse->method('getStatusCode')->willReturn(200);
        $releaseResponse->method('toArray')->willReturn($releaseData);

        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getContent')->willReturn('phar binary content');

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($releaseResponse, $downloadResponse) {
                if (str_contains($url, '/releases/latest')) {
                    return $releaseResponse;
                }
                return $downloadResponse;
            });

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Update failed: The file is not writable', $outputText);
        $this->assertStringContainsString('sudo stud update', $outputText);
    }

    public function testHandleWithNoPharAsset(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $releaseData = [
            'tag_name' => 'v1.0.1',
            'assets' => [
                [
                    'name' => 'readme.md',
                    'browser_download_url' => 'https://github.com/studapart/stud-cli/releases/download/v1.0.1/readme.md',
                ],
            ],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn($releaseData);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', '/repos/studapart/stud-cli/releases/latest')
            ->willReturn($response);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Could not find stud.phar asset', $outputText);
    }

    public function testHandleWithNoReleasesFound(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('getContent')->willReturn('{"message":"Not Found"}');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', '/repos/studapart/stud-cli/releases/latest')
            ->willReturn($response);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('No releases found for this repository', $outputText);
    }
}

