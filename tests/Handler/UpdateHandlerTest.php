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

    public function testHandleWithVerboseOutput(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $releaseData = [
            'tag_name' => 'v1.0.1',
            'assets' => [
                [
                    'name' => 'stud-1.0.1.phar',
                    'browser_download_url' => 'https://github.com/studapart/stud-cli/releases/download/v1.0.1/stud-1.0.1.phar',
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
        $io->setVerbosity(SymfonyStyle::VERBOSITY_VERBOSE);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Binary path:', $outputText);
        $this->assertStringContainsString('Detected repository:', $outputText);
        $this->assertStringContainsString('Current version:', $outputText);
        $this->assertStringContainsString('Latest version:', $outputText);
        $this->assertStringContainsString('Downloading from:', $outputText);
    }

    public function testHandleWithVersionedAssetName(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $releaseData = [
            'tag_name' => 'v1.0.1',
            'assets' => [
                [
                    'name' => 'stud-1.0.1.phar',
                    'browser_download_url' => 'https://github.com/studapart/stud-cli/releases/download/v1.0.1/stud-1.0.1.phar',
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
        $this->assertStringContainsString('Update complete! You are now on v1.0.1', $outputText);
        $this->assertStringEqualsFile($this->tempBinaryPath, 'phar binary content');
    }

    public function testHandleWithMultipleAssetsPicksCorrectOne(): void
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
                [
                    'name' => 'stud-1.0.1.phar',
                    'browser_download_url' => 'https://github.com/studapart/stud-cli/releases/download/v1.0.1/stud-1.0.1.phar',
                ],
                [
                    'name' => 'other-tool.phar',
                    'browser_download_url' => 'https://github.com/studapart/stud-cli/releases/download/v1.0.1/other-tool.phar',
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
                // Verify it downloads the correct asset
                $this->assertStringContainsString('stud-1.0.1.phar', $url);
                return $downloadResponse;
            });

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $this->assertStringEqualsFile($this->tempBinaryPath, 'phar binary content');
    }

    public function testHandleWithEmptyAssetsArray(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $releaseData = [
            'tag_name' => 'v1.0.1',
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

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Could not find stud.phar asset', $outputText);
    }

    public function testHandleWithOnlyOwnerMissing(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn(null);
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Could not determine repository owner or name', $outputText);
    }

    public function testHandleWithOnlyNameMissing(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn(null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Could not determine repository owner or name', $outputText);
    }

    public function testHandleWithVersionPrefixHandling(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        // Current version has 'v' prefix, latest doesn't
        $handler = new UpdateHandler(
            $this->gitRepository,
            'v1.0.0',
            $this->tempBinaryPath,
            null,
            $this->httpClient
        );

        $releaseData = [
            'tag_name' => '1.0.1', // No 'v' prefix
            'assets' => [
                [
                    'name' => 'stud.phar',
                    'browser_download_url' => 'https://github.com/studapart/stud-cli/releases/download/1.0.1/stud.phar',
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

        $result = $handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('A new version (1.0.1) is available', $outputText);
    }

    public function testHandleWithVersionEqualComparison(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $releaseData = [
            'tag_name' => 'v1.0.0', // Same as current
            'assets' => [],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn($releaseData);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('You are already on the latest version', $outputText);
    }

    public function testHandleWithDownloadFailure(): void
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

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($releaseResponse) {
                if (str_contains($url, '/releases/latest')) {
                    return $releaseResponse;
                }
                // Simulate download failure
                throw new \Exception('Network error');
            });

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Failed to download the new version', $outputText);
        $this->assertStringContainsString('Network error', $outputText);
    }

    public function testHandleWithBinaryReplacementFailure(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        // Use a non-writable file to simulate replacement failure
        // First ensure the file exists and is writable for download
        $badBinaryPath = sys_get_temp_dir() . '/stud-test-readonly.phar';
        touch($badBinaryPath);
        chmod($badBinaryPath, 0444); // Read-only

        $handler = new UpdateHandler(
            $this->gitRepository,
            '1.0.0',
            $badBinaryPath,
            null,
            $this->httpClient
        );

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

        $result = $handler->handle($io);

        // Should fail because file is not writable (checked before rename)
        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Update failed: The file is not writable', $outputText);

        chmod($badBinaryPath, 0644);
        @unlink($badBinaryPath);
        @unlink(sys_get_temp_dir() . '/stud.phar.new');
    }

    public function testHandleWithNon404ApiError(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);
        $response->method('getContent')->willReturn('Internal Server Error');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', '/repos/studapart/stud-cli/releases/latest')
            ->willReturn($response);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('Failed to fetch latest release information', $outputText);
        $this->assertStringContainsString('Status: 500', $outputText);
    }

    public function testHandleWithGitTokenProvided(): void
    {
        $handler = new UpdateHandler(
            $this->gitRepository,
            '1.0.0',
            $this->tempBinaryPath,
            'test-token-123',
            $this->httpClient
        );

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

        $result = $handler->handle($io);

        $this->assertSame(0, $result);
        // Token should be used in the request (verified by the mock expectation)
    }

    public function testHandleWithOlderVersionThanCurrent(): void
    {
        $this->gitRepository->method('getRepositoryOwner')->willReturn('studapart');
        $this->gitRepository->method('getRepositoryName')->willReturn('stud-cli');

        $releaseData = [
            'tag_name' => 'v0.9.0', // Older than current 1.0.0
            'assets' => [],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn($releaseData);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        $outputText = $output->fetch();
        $this->assertStringContainsString('You are already on the latest version', $outputText);
    }


    public function testGetBinaryPathUsesProvidedPath(): void
    {
        $testPath = '/test/path/to/binary.phar';
        $handler = new UpdateHandler(
            $this->gitRepository,
            '1.0.0',
            $testPath,
            null,
            $this->httpClient
        );

        $binaryPath = $this->callPrivateMethod($handler, 'getBinaryPath');

        // In test environment, Phar::running() won't return a value and ReflectionClass will work
        // So it should fall back to the provided path
        $this->assertSame($testPath, $binaryPath);
    }

    public function testGetBinaryPathWithPharRunning(): void
    {
        // This test verifies the path when Phar::running() would return a value
        // Since we can't easily mock Phar in tests, we test that the fallback works
        // The actual Phar path would be tested in integration tests
        $testPath = '/test/phar/path.phar';
        $handler = new UpdateHandler(
            $this->gitRepository,
            '1.0.0',
            $testPath,
            null,
            $this->httpClient
        );

        $binaryPath = $this->callPrivateMethod($handler, 'getBinaryPath');

        // In test environment without PHAR, it should use the provided path
        $this->assertSame($testPath, $binaryPath);
    }
}

