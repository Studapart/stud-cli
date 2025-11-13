<?php

namespace App\Tests\Service;

use App\Service\GithubProvider;
use App\Service\VersionCheckService;
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
    private string $tempCacheDir;
    private string $tempCacheFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->tempCacheDir = sys_get_temp_dir() . '/stud-test-cache-' . uniqid();
        // Cache file should be in .cache/stud/ subdirectory
        $this->tempCacheFile = $this->tempCacheDir . '/.cache/stud/last_update_check.json';

        // Create cache directory structure
        @mkdir(dirname($this->tempCacheFile), 0755, true);

        $this->service = new VersionCheckService(
            self::REPO_OWNER,
            self::REPO_NAME,
            self::CURRENT_VERSION,
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
            'token',
            $this->httpClientMock
        );

        $this->assertInstanceOf(VersionCheckService::class, $service);
    }

    protected function tearDown(): void
    {
        // Clean up cache file and directory
        @unlink($this->tempCacheFile);
        @rmdir(dirname($this->tempCacheFile)); // .cache/stud
        @rmdir($this->tempCacheDir . '/.cache'); // .cache
        @rmdir($this->tempCacheDir);
        parent::tearDown();
    }

    public function testCheckForUpdateWithFreshCache(): void
    {
        // Create a fresh cache file (less than 24 hours old)
        $cacheData = [
            'latest_version' => '1.2.0',
            'timestamp' => time() - 3600, // 1 hour ago
        ];
        file_put_contents($this->tempCacheFile, json_encode($cacheData));

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
        // Create a stale cache file (more than 24 hours old)
        $cacheData = [
            'latest_version' => '1.0.0',
            'timestamp' => time() - 86401, // 24 hours + 1 second ago
        ];
        file_put_contents($this->tempCacheFile, json_encode($cacheData));

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
            $updatedCache = json_decode(file_get_contents($this->tempCacheFile), true);
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
            $this->assertFileExists($this->tempCacheFile);
            $cache = json_decode(file_get_contents($this->tempCacheFile), true);
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
            $this->assertFileExists($this->tempCacheFile);
            $cache = json_decode(file_get_contents($this->tempCacheFile), true);
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

        $tempDir = sys_get_temp_dir() . '/stud-test-cache-dir-' . uniqid();
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $tempDir;

        try {
            // Remove directory if it exists
            @rmdir($tempDir . '/.cache/stud');
            @rmdir($tempDir . '/.cache');
            @rmdir($tempDir);

            $path = $method->invoke($this->service);
            $this->assertStringContainsString('.cache/stud/last_update_check.json', $path);
            $this->assertDirectoryExists(dirname($path));
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
            @rmdir($tempDir . '/.cache/stud');
            @rmdir($tempDir . '/.cache');
            @rmdir($tempDir);
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

        // Create a directory path (not a file) to simulate read failure
        $nonReadablePath = sys_get_temp_dir() . '/stud-test-' . uniqid();
        @mkdir($nonReadablePath, 0000);

        try {
            $result = $method->invoke($this->service, $nonReadablePath . '/file.json');
            $this->assertNull($result);
        } finally {
            @chmod($nonReadablePath, 0755);
            @rmdir($nonReadablePath);
        }
    }

    public function testReadCacheReturnsNullWhenJsonIsInvalid(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readCache');
        $method->setAccessible(true);

        $invalidJsonFile = sys_get_temp_dir() . '/stud-test-invalid-' . uniqid() . '.json';
        file_put_contents($invalidJsonFile, 'invalid json content');

        try {
            $result = $method->invoke($this->service, $invalidJsonFile);
            $this->assertNull($result);
        } finally {
            @unlink($invalidJsonFile);
        }
    }

    public function testReadCacheReturnsNullWhenTimestampMissing(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readCache');
        $method->setAccessible(true);

        $cacheFile = sys_get_temp_dir() . '/stud-test-no-timestamp-' . uniqid() . '.json';
        file_put_contents($cacheFile, json_encode(['latest_version' => '1.2.0']));

        try {
            $result = $method->invoke($this->service, $cacheFile);
            $this->assertNull($result);
        } finally {
            @unlink($cacheFile);
        }
    }

    public function testCreateGithubProviderWithoutToken(): void
    {
        $service = new VersionCheckService(
            self::REPO_OWNER,
            self::REPO_NAME,
            self::CURRENT_VERSION,
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
        file_put_contents($this->tempCacheFile, json_encode($cacheData));

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

    public function testWriteCache(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('writeCache');
        $method->setAccessible(true);

        $testCacheFile = sys_get_temp_dir() . '/stud-test-write-' . uniqid() . '.json';
        @mkdir(dirname($testCacheFile), 0755, true);

        try {
            $method->invoke($this->service, $testCacheFile, '1.2.0');

            $this->assertFileExists($testCacheFile);
            $data = json_decode(file_get_contents($testCacheFile), true);
            $this->assertSame('1.2.0', $data['latest_version']);
            $this->assertIsInt($data['timestamp']);
        } finally {
            @unlink($testCacheFile);
            @rmdir(dirname($testCacheFile));
        }
    }

    public function testWriteCacheWithNullVersion(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('writeCache');
        $method->setAccessible(true);

        $testCacheFile = sys_get_temp_dir() . '/stud-test-write-null-' . uniqid() . '.json';
        @mkdir(dirname($testCacheFile), 0755, true);

        try {
            $method->invoke($this->service, $testCacheFile, null);

            $this->assertFileExists($testCacheFile);
            $data = json_decode(file_get_contents($testCacheFile), true);
            $this->assertNull($data['latest_version']);
            $this->assertIsInt($data['timestamp']);
        } finally {
            @unlink($testCacheFile);
            @rmdir(dirname($testCacheFile));
        }
    }
}

