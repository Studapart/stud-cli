<?php

namespace App\Tests\Service;

use App\Service\FileSystem;
use App\Service\ReleaseAssetDownloader;
use App\Service\UpdateRepositoryContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ReleaseAssetDownloaderTest extends TestCase
{
    private const ASSET_URL = 'https://api.github.com/repos/studapart/stud-cli/releases/assets/12345678';

    public function testAssetUrlTargetsRepositoryReleaseAsset(): void
    {
        $downloader = new ReleaseAssetDownloader(
            new UpdateRepositoryContext('studapart', 'stud-cli'),
            $this->createStub(FileSystem::class),
            null,
        );

        $this->assertSame(self::ASSET_URL, $downloader->assetUrl(12345678));
    }

    public function testDownloadWritesResponseBodyToTempFile(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->assertSame('GET', $method);
            $this->assertSame(self::ASSET_URL, $url);

            return new MockResponse('phar binary content');
        });
        $writtenPath = null;
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->expects($this->once())
            ->method('filePutContents')
            ->willReturnCallback(function (string $path, string $contents) use (&$writtenPath): void {
                $writtenPath = $path;
                $this->assertSame('phar binary content', $contents);
                $this->assertMatchesRegularExpression('#/stud\.phar\.[0-9a-f]{16}$#', $path);
            });

        $downloader = new ReleaseAssetDownloader(new UpdateRepositoryContext('studapart', 'stud-cli', 'token'), $fileSystem, $httpClient);

        $downloaded = $downloader->download(self::ASSET_URL);

        $this->assertSame($writtenPath, $downloaded);
    }

    public function testDownloadUsesADistinctTempFileEachCall(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('one'),
            new MockResponse('two'),
        ]);
        $paths = [];
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->expects($this->exactly(2))
            ->method('filePutContents')
            ->willReturnCallback(function (string $path) use (&$paths): void {
                $paths[] = $path;
            });

        $downloader = new ReleaseAssetDownloader(new UpdateRepositoryContext('studapart', 'stud-cli'), $fileSystem, $httpClient);

        $first = $downloader->download(self::ASSET_URL);
        $second = $downloader->download(self::ASSET_URL);

        $this->assertSame([$first, $second], $paths);
        $this->assertNotSame($first, $second);
    }

    public function testDownloadPropagatesHttpFailureWithoutWriting(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Network error'));
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->expects($this->never())->method('filePutContents');

        $downloader = new ReleaseAssetDownloader(new UpdateRepositoryContext('studapart', 'stud-cli'), $fileSystem, $httpClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Network error');
        $downloader->download(self::ASSET_URL);
    }
}
