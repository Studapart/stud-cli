<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Handler\UpdateHandler;
use App\Service\ChangelogParser;
use App\Service\FileSystem;
use App\Service\Logger;
use App\Service\TranslationService;
use App\Service\UpdateFileService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Integration tests for UpdateHandler.
 *
 * These tests verify end-to-end behavior with mocked HTTP clients to avoid real network calls.
 * Marked as integration tests to differentiate from unit tests.
 */
#[Group('integration')]
class UpdateHandlerIntegrationTest extends TestCase
{
    private UpdateHandler $handler;
    private HttpClientInterface $httpClient;
    private FileSystem $fileSystem;
    private TranslationService $translationService;
    private string $tempBinaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Use in-memory filesystem for integration tests
        $adapter = new \League\Flysystem\InMemory\InMemoryFilesystemAdapter();
        $flysystem = new \League\Flysystem\Filesystem($adapter);
        $this->fileSystem = new FileSystem($flysystem);

        // Create real services (not mocks) for integration testing
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translationService = new TranslationService('en', $translationsPath);

        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->tempBinaryPath = sys_get_temp_dir() . '/stud-test.phar';

        // Create handler with real services but mocked HTTP client
        $this->handler = new UpdateHandler(
            'studapart',
            'stud-cli',
            '1.0.0',
            $this->tempBinaryPath,
            $this->translationService,
            new ChangelogParser(),
            new UpdateFileService($this->translationService),
            new Logger(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), []),
            $this->fileSystem,
            null,
            $this->httpClient
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->tempBinaryPath)) {
            @unlink($this->tempBinaryPath);
        }
        if (file_exists($this->tempBinaryPath . '-1.0.0.bak')) {
            @unlink($this->tempBinaryPath . '-1.0.0.bak');
        }
    }

    /**
     * Integration test for update workflow with mocked GitHub API.
     * Tests the end-to-end flow: fetch release -> download -> verify hash -> update.
     */
    public function testUpdateWorkflowEndToEnd(): void
    {
        $pharContent = 'phar binary content';
        $pharHash = hash('sha256', $pharContent);

        $releaseData = [
            'tag_name' => 'v1.0.1',
            'assets' => [
                [
                    'id' => 12345678,
                    'name' => 'stud.phar',
                    'digest' => 'sha256:' . $pharHash,
                ],
            ],
        ];

        $changelogContent = <<<'CHANGELOG'
## [1.0.1] - 2025-01-01

### Added
- New feature

CHANGELOG;

        // Mock release API response
        $releaseResponse = $this->createMock(ResponseInterface::class);
        $releaseResponse->method('getStatusCode')->willReturn(200);
        $releaseResponse->method('toArray')->willReturn($releaseData);

        // Mock changelog API response
        $changelogResponse = $this->createMock(ResponseInterface::class);
        $changelogResponse->method('getStatusCode')->willReturn(200);
        $changelogResponse->method('toArray')->willReturn([
            'content' => base64_encode($changelogContent),
            'encoding' => 'base64',
        ]);

        // Mock download response
        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getContent')->willReturn($pharContent);

        // Setup HTTP client to return appropriate responses
        $this->httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($releaseResponse, $changelogResponse, $downloadResponse) {
                if (str_contains($url, '/releases/latest')) {
                    return $releaseResponse;
                }
                if (str_contains($url, '/contents/CHANGELOG.md')) {
                    return $changelogResponse;
                }
                if (str_contains($url, '/releases/assets/')) {
                    return $downloadResponse;
                }

                $this->fail('Unexpected API call: ' . $url);

                return $releaseResponse;
            });

        // Create initial binary file
        file_put_contents($this->tempBinaryPath, 'old binary content');

        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $io = new SymfonyStyle($input, $output);

        $result = $this->handler->handle($io);

        // Verify update completed successfully
        $this->assertSame(0, $result);
        $this->assertFileExists($this->tempBinaryPath);
        $this->assertStringEqualsFile($this->tempBinaryPath, $pharContent);
    }

    /**
     * Integration test for hash verification with real file content.
     * Tests that hash verification works correctly with actual file data.
     */
    public function testHashVerificationWithRealFile(): void
    {
        $pharContent = 'phar binary content for hash test';
        $pharHash = hash('sha256', $pharContent);

        $releaseData = [
            'tag_name' => 'v1.0.1',
            'assets' => [
                [
                    'id' => 12345678,
                    'name' => 'stud.phar',
                    'digest' => 'sha256:' . $pharHash,
                ],
            ],
        ];

        $releaseResponse = $this->createMock(ResponseInterface::class);
        $releaseResponse->method('getStatusCode')->willReturn(200);
        $releaseResponse->method('toArray')->willReturn($releaseData);

        $changelogResponse = $this->createMock(ResponseInterface::class);
        $changelogResponse->method('getStatusCode')->willReturn(200);
        $changelogResponse->method('toArray')->willReturn([
            'content' => base64_encode('## [1.0.1] - 2025-01-01'),
            'encoding' => 'base64',
        ]);

        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getContent')->willReturn($pharContent);

        $this->httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $url) use ($releaseResponse, $changelogResponse, $downloadResponse) {
                if (str_contains($url, '/releases/latest')) {
                    return $releaseResponse;
                }
                if (str_contains($url, '/contents/CHANGELOG.md')) {
                    return $changelogResponse;
                }

                return $downloadResponse;
            });

        file_put_contents($this->tempBinaryPath, 'old content');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        // Hash verification should pass and update should succeed
        $this->assertSame(0, $result);
        $this->assertFileExists($this->tempBinaryPath);
        $this->assertStringEqualsFile($this->tempBinaryPath, $pharContent);
    }
}
