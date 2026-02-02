<?php

namespace App\Tests\Service;

use App\Service\FileSystem;
use App\Service\GithubProvider;
use App\Service\VersionCheckService;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class VersionCheckServiceTest extends TestCase
{
    private const REPO_OWNER = 'test_owner';
    private const REPO_NAME = 'test_repo';
    private const CURRENT_VERSION = '1.1.0';
    private const GIT_TOKEN = 'test_token';

    private VersionCheckService $service;
    private HttpClientInterface&MockObject $httpClientMock;
    private FileSystem $fileSystem;
    private FlysystemFilesystem $flysystem;
    private string $tempCacheDir;
    private string $tempCacheFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Create in-memory filesystem
        $adapter = new InMemoryFilesystemAdapter();
        $this->flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($this->flysystem);

        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->tempCacheDir = '/test/home';
        // Cache file should be in .cache/stud/ subdirectory
        $this->tempCacheFile = $this->tempCacheDir . '/.cache/stud/last_update_check.json';

        // Create cache directory structure in memory
        $this->flysystem->createDirectory($this->tempCacheDir . '/.cache/stud');

        $this->service = new VersionCheckService(
            self::REPO_OWNER,
            self::REPO_NAME,
            self::CURRENT_VERSION,
            $this->fileSystem,
            self::GIT_TOKEN,
            $this->httpClientMock
        );
    }

    public function testConstructor(): void
    {
        $service = new VersionCheckService(
            'owner',
            'repo',
            '1.0.0',
            $this->fileSystem,
            'token',
            $this->httpClientMock
        );

        $this->assertInstanceOf(VersionCheckService::class, $service);
    }

    public function testCheckForUpdateWithFreshCache(): void
    {
        // Create a fresh cache file (less than 24 hours old) in in-memory filesystem
        $cacheData = [
            'latest_version' => '1.2.0',
            'timestamp' => time() - 3600, // 1 hour ago
        ];
        $this->flysystem->write($this->tempCacheFile, json_encode($cacheData));

        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            // Should not call GitHub API when cache is fresh
            $this->httpClientMock->expects($this->never())
                ->method('request');

            $result = $this->service->checkForUpdate();

            $this->assertSame('1.2.0', $result['latest_version']);
            $this->assertTrue($result['should_display']);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testCheckForUpdateWithStaleCache(): void
    {
        // Create a stale cache file (more than 24 hours old) in in-memory filesystem
        $cacheData = [
            'latest_version' => '1.0.0',
            'timestamp' => time() - 86401, // 24 hours + 1 second ago
        ];
        $this->flysystem->write($this->tempCacheFile, json_encode($cacheData));

        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            // Mock GitHub API response
            $releaseData = [
                'tag_name' => 'v1.2.0',
            ];

            $responseMock = $this->createMock(ResponseInterface::class);
            $responseMock->method('getStatusCode')->willReturn(200);
            $responseMock->method('toArray')->willReturn($releaseData);

            $this->httpClientMock->expects($this->once())
                ->method('request')
                ->with('GET', '/repos/' . self::REPO_OWNER . '/' . self::REPO_NAME . '/releases/latest')
                ->willReturn($responseMock);

            $result = $this->service->checkForUpdate();

            $this->assertSame('1.2.0', $result['latest_version']);
            $this->assertTrue($result['should_display']);

            // Verify cache was updated
            $updatedCache = json_decode($this->fileSystem->read($this->tempCacheFile), true);
            $this->assertSame('1.2.0', $updatedCache['latest_version']);
            $this->assertGreaterThan(time() - 10, $updatedCache['timestamp']);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testCheckForUpdateWithNoCache(): void
    {
        // No cache file exists

        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            // Mock GitHub API response
            $releaseData = [
                'tag_name' => 'v1.2.0',
            ];

            $responseMock = $this->createMock(ResponseInterface::class);
            $responseMock->method('getStatusCode')->willReturn(200);
            $responseMock->method('toArray')->willReturn($releaseData);

            $this->httpClientMock->expects($this->once())
                ->method('request')
                ->willReturn($responseMock);

            $result = $this->service->checkForUpdate();

            $this->assertSame('1.2.0', $result['latest_version']);
            $this->assertTrue($result['should_display']);

            // Verify cache was created
            $this->assertTrue($this->fileSystem->fileExists($this->tempCacheFile));
            $cache = json_decode($this->fileSystem->read($this->tempCacheFile), true);
            $this->assertSame('1.2.0', $cache['latest_version']);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testCheckForUpdateFailsSilentlyOnGitHubError(): void
    {
        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            // Mock GitHub API error
            $responseMock = $this->createMock(ResponseInterface::class);
            $responseMock->method('getStatusCode')->willReturn(500);
            $responseMock->method('getContent')->willReturn('Internal Server Error');

            $this->httpClientMock->expects($this->once())
                ->method('request')
                ->willReturn($responseMock);

            // Should not throw exception, should fail silently
            $result = $this->service->checkForUpdate();

            $this->assertNull($result['latest_version']);
            $this->assertFalse($result['should_display']);

            // Cache should still be written (with null version)
            $this->assertTrue($this->fileSystem->fileExists($this->tempCacheFile));
            $cache = json_decode($this->fileSystem->read($this->tempCacheFile), true);
            $this->assertNull($cache['latest_version']);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testCheckForUpdateDoesNotDisplayWhenAlreadyOnLatest(): void
    {
        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            // Mock GitHub API response with same version
            $releaseData = [
                'tag_name' => 'v1.1.0',
            ];

            $responseMock = $this->createMock(ResponseInterface::class);
            $responseMock->method('getStatusCode')->willReturn(200);
            $responseMock->method('toArray')->willReturn($releaseData);

            $this->httpClientMock->expects($this->once())
                ->method('request')
                ->willReturn($responseMock);

            $result = $this->service->checkForUpdate();

            $this->assertSame('1.1.0', $result['latest_version']);
            $this->assertFalse($result['should_display']);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testCheckForUpdateDoesNotDisplayWhenOnNewerVersion(): void
    {
        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            // Mock GitHub API response with older version
            $releaseData = [
                'tag_name' => 'v1.0.0',
            ];

            $responseMock = $this->createMock(ResponseInterface::class);
            $responseMock->method('getStatusCode')->willReturn(200);
            $responseMock->method('toArray')->willReturn($releaseData);

            $this->httpClientMock->expects($this->once())
                ->method('request')
                ->willReturn($responseMock);

            $result = $this->service->checkForUpdate();

            $this->assertSame('1.0.0', $result['latest_version']);
            $this->assertFalse($result['should_display']);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testIsNewerVersion(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isNewerVersion');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->service, '1.2.0'));
        $this->assertTrue($method->invoke($this->service, 'v1.2.0'));
        $this->assertFalse($method->invoke($this->service, '1.1.0'));
        $this->assertFalse($method->invoke($this->service, '1.0.0'));
        $this->assertFalse($method->invoke($this->service, 'v1.1.0'));
    }

    public function testIsCacheFresh(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isCacheFresh');
        $method->setAccessible(true);

        // Fresh cache (1 hour ago)
        $freshCache = ['timestamp' => time() - 3600];
        $this->assertTrue($method->invoke($this->service, $freshCache));

        // Stale cache (25 hours ago)
        $staleCache = ['timestamp' => time() - 90000];
        $this->assertFalse($method->invoke($this->service, $staleCache));
    }

    public function testGetCachePathThrowsExceptionWhenHomeNotSet(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getCachePath');
        $method->setAccessible(true);

        $originalHome = $_SERVER['HOME'] ?? null;
        unset($_SERVER['HOME']);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not determine home directory.');
            $method->invoke($this->service);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            }
        }
    }

    public function testGetCachePathCreatesDirectoryIfNotExists(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getCachePath');
        $method->setAccessible(true);

        $tempDir = '/test/cache-dir';
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $tempDir;

        try {
            // Verify directory doesn't exist before calling getCachePath
            $this->assertFalse($this->fileSystem->isDir($tempDir . '/.cache/stud'));

            $path = $method->invoke($this->service);
            $this->assertStringContainsString('.cache/stud/last_update_check.json', $path);
            $this->assertTrue($this->fileSystem->isDir(dirname($path)));
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testGetCachePathWhenDirectoryAlreadyExists(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getCachePath');
        $method->setAccessible(true);

        $tempDir = '/test/cache-exists';
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $tempDir;

        try {
            // Create directory structure first in in-memory filesystem
            $this->flysystem->createDirectory($tempDir . '/.cache/stud');

            // Verify directory exists
            $this->assertTrue($this->fileSystem->isDir($tempDir . '/.cache/stud'));

            // Call getCachePath - should skip mkdir since directory exists
            $path = $method->invoke($this->service);
            $this->assertStringContainsString('.cache/stud/last_update_check.json', $path);
            $this->assertTrue($this->fileSystem->isDir(dirname($path)));
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testCheckForUpdateCreatesCacheDirectoryIfNotExists(): void
    {
        // Create a service with a fresh temp directory that doesn't have cache dir
        $tempDir = '/test/no-cache-dir';
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $tempDir;

        try {
            // Ensure cache directory doesn't exist in in-memory filesystem
            if ($this->flysystem->directoryExists($tempDir . '/.cache/stud')) {
                $this->flysystem->deleteDirectory($tempDir . '/.cache/stud');
            }
            $this->assertFalse($this->fileSystem->isDir($tempDir . '/.cache/stud'));

            // Mock GitHub API response
            $releaseData = [
                'tag_name' => 'v1.2.0',
            ];

            $responseMock = $this->createMock(ResponseInterface::class);
            $responseMock->method('getStatusCode')->willReturn(200);
            $responseMock->method('toArray')->willReturn($releaseData);

            $this->httpClientMock->expects($this->once())
                ->method('request')
                ->willReturn($responseMock);

            // This should create the directory
            $result = $this->service->checkForUpdate();

            // Verify directory was created
            $this->assertTrue($this->fileSystem->isDir($tempDir . '/.cache/stud'));
            $this->assertSame('1.2.0', $result['latest_version']);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testReadCacheReturnsNullWhenFileDoesNotExist(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readCache');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '/nonexistent/path/file.json');
        $this->assertNull($result);
    }

    public function testReadCacheReturnsNullWhenFileGetContentsFails(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readCache');
        $method->setAccessible(true);

        // Create a mock FileSystem that throws RuntimeException when reading
        $mockFileSystem = $this->createMock(FileSystem::class);
        $mockFileSystem->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);
        $mockFileSystem->expects($this->once())
            ->method('read')
            ->willThrowException(new \RuntimeException('Read error'));

        // Create a new service instance with the mock FileSystem
        $service = new VersionCheckService(
            self::REPO_OWNER,
            self::REPO_NAME,
            self::CURRENT_VERSION,
            $mockFileSystem,
            self::GIT_TOKEN,
            $this->httpClientMock
        );

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('readCache');
        $method->setAccessible(true);

        $result = $method->invoke($service, '/test/file.json');
        $this->assertNull($result);
    }

    public function testReadCacheReturnsNullWhenJsonIsInvalid(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readCache');
        $method->setAccessible(true);

        $invalidJsonFile = '/test/invalid.json';
        $this->flysystem->write($invalidJsonFile, 'invalid json content');

        $result = $method->invoke($this->service, $invalidJsonFile);
        $this->assertNull($result);
    }

    public function testReadCacheReturnsNullWhenTimestampMissing(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readCache');
        $method->setAccessible(true);

        $cacheFile = '/test/no-timestamp.json';
        $this->flysystem->write($cacheFile, json_encode(['latest_version' => '1.2.0']));

        $result = $method->invoke($this->service, $cacheFile);
        $this->assertNull($result);
    }

    public function testCreateGithubProviderWithoutToken(): void
    {
        $service = new VersionCheckService(
            self::REPO_OWNER,
            self::REPO_NAME,
            self::CURRENT_VERSION,
            $this->fileSystem,
            null, // No token
            $this->httpClientMock
        );

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('createGithubProvider');
        $method->setAccessible(true);

        $provider = $method->invoke($service);
        $this->assertInstanceOf(GithubProvider::class, $provider);
    }

    public function testCreateGithubProviderWithToken(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('createGithubProvider');
        $method->setAccessible(true);

        $provider = $method->invoke($this->service);
        $this->assertInstanceOf(GithubProvider::class, $provider);
    }

    public function testCreateGithubProviderWithoutHttpClient(): void
    {
        $service = new VersionCheckService(
            self::REPO_OWNER,
            self::REPO_NAME,
            self::CURRENT_VERSION,
            $this->fileSystem,
            self::GIT_TOKEN,
            null // No httpClient provided
        );

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('createGithubProvider');
        $method->setAccessible(true);

        $provider = $method->invoke($service);
        $this->assertInstanceOf(GithubProvider::class, $provider);
    }

    public function testCreateGithubProviderWithoutTokenAndWithoutHttpClient(): void
    {
        $service = new VersionCheckService(
            self::REPO_OWNER,
            self::REPO_NAME,
            self::CURRENT_VERSION,
            $this->fileSystem,
            null, // No token
            null  // No httpClient
        );

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('createGithubProvider');
        $method->setAccessible(true);

        $provider = $method->invoke($service);
        $this->assertInstanceOf(GithubProvider::class, $provider);
    }

    public function testFetchLatestVersionFromGitHubWithNoTagName(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('fetchLatestVersionFromGitHub');
        $method->setAccessible(true);

        // Override HOME
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            $releaseData = [
                // No tag_name
            ];

            $responseMock = $this->createMock(ResponseInterface::class);
            $responseMock->method('getStatusCode')->willReturn(200);
            $responseMock->method('toArray')->willReturn($releaseData);

            $this->httpClientMock->expects($this->once())
                ->method('request')
                ->willReturn($responseMock);

            $result = $method->invoke($this->service);
            $this->assertSame('', $result); // Empty string after ltrim
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testCheckForUpdateWithFreshCacheButNullVersion(): void
    {
        // Create a fresh cache file with null latest_version
        $cacheData = [
            'latest_version' => null,
            'timestamp' => time() - 3600, // 1 hour ago
        ];
        $this->flysystem->write($this->tempCacheFile, json_encode($cacheData));

        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            // Should not call GitHub API when cache is fresh
            $this->httpClientMock->expects($this->never())
                ->method('request');

            $result = $this->service->checkForUpdate();

            $this->assertNull($result['latest_version']);
            $this->assertFalse($result['should_display']);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testCheckForUpdateShouldDisplayWhenNewerVersionAvailable(): void
    {
        // Test that should_display is true when a newer version is available
        // This covers line 55: 'should_display' => $latestVersion !== null && $this->isNewerVersion($latestVersion)
        // Override HOME to use our temp directory
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempCacheDir;

        try {
            // Mock GitHub API response with newer version
            $releaseData = [
                'tag_name' => 'v1.2.0', // Newer than current version 1.1.0
            ];

            $responseMock = $this->createMock(ResponseInterface::class);
            $responseMock->method('getStatusCode')->willReturn(200);
            $responseMock->method('toArray')->willReturn($releaseData);

            $this->httpClientMock->expects($this->once())
                ->method('request')
                ->willReturn($responseMock);

            $result = $this->service->checkForUpdate();

            $this->assertSame('1.2.0', $result['latest_version']);
            $this->assertTrue($result['should_display'], 'should_display should be true when newer version is available');
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testWriteCache(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('writeCache');
        $method->setAccessible(true);

        $testCacheFile = '/test/write.json';
        $this->flysystem->createDirectory(dirname($testCacheFile));

        $method->invoke($this->service, $testCacheFile, '1.2.0');

        $this->assertTrue($this->fileSystem->fileExists($testCacheFile));
        $data = json_decode($this->fileSystem->read($testCacheFile), true);
        $this->assertSame('1.2.0', $data['latest_version']);
        $this->assertIsInt($data['timestamp']);
    }

    public function testWriteCacheWithNullVersion(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('writeCache');
        $method->setAccessible(true);

        $testCacheFile = '/test/write-null.json';
        $this->flysystem->createDirectory(dirname($testCacheFile));

        $method->invoke($this->service, $testCacheFile, null);

        $this->assertTrue($this->fileSystem->fileExists($testCacheFile));
        $data = json_decode($this->fileSystem->read($testCacheFile), true);
        $this->assertNull($data['latest_version']);
        $this->assertIsInt($data['timestamp']);
    }

    public function testGetCachePathThrowsExceptionWhenMkdirFails(): void
    {
        // Test lines 69-70: RuntimeException when mkdir() fails
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getCachePath');
        $method->setAccessible(true);

        $tempDir = '/test/mkdir-fails';
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $tempDir;

        // Create a mock FileSystem that throws RuntimeException on mkdir
        $mockFileSystem = $this->createMock(FileSystem::class);
        $mockFileSystem->method('isDir')
            ->willReturn(false); // Directory doesn't exist
        $mockFileSystem->method('mkdir')
            ->willThrowException(new \RuntimeException('Mkdir failed'));

        $service = new VersionCheckService(
            self::REPO_OWNER,
            self::REPO_NAME,
            self::CURRENT_VERSION,
            $mockFileSystem,
            self::GIT_TOKEN,
            $this->httpClientMock
        );

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('getCachePath');
        $method->setAccessible(true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to create cache directory:');
            $method->invoke($service);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }
}
