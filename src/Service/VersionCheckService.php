<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class VersionCheckService
{
    private const CACHE_FILE_PATH = '~/.cache/stud/last_update_check.json';
    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly string $repoOwner,
        private readonly string $repoName,
        private readonly string $currentVersion,
        private ?string $gitToken = null,
        private ?HttpClientInterface $httpClient = null,
        private ?FileSystem $fileSystem = null
    ) {
    }

    /**
     * Checks if an update is available by checking cache first, then GitHub API if needed.
     * Returns the latest version if available, null otherwise.
     * This method is non-blocking and fails silently on errors.
     *
     * @return array{latest_version: string|null, should_display: bool}
     */
    public function checkForUpdate(): array
    {
        $cachePath = $this->getCachePath();
        $cacheData = $this->readCache($cachePath);

        // If cache is fresh (less than 24 hours old), use cached data
        if ($cacheData !== null && $this->isCacheFresh($cacheData)) {
            $latestVersion = $cacheData['latest_version'] ?? null;

            return [
                'latest_version' => $latestVersion,
                'should_display' => $latestVersion !== null && $this->isNewerVersion($latestVersion),
            ];
        }

        // Cache is stale or doesn't exist, fetch from GitHub API
        $latestVersion = $this->fetchLatestVersionFromGitHub();

        // Write to cache regardless of whether fetch succeeded
        $this->writeCache($cachePath, $latestVersion);

        return [
            'latest_version' => $latestVersion,
            'should_display' => $latestVersion !== null && $this->isNewerVersion($latestVersion),
        ];
    }

    protected function getCachePath(): string
    {
        $home = $_SERVER['HOME'] ?? throw new \RuntimeException('Could not determine home directory.');
        $path = str_replace('~', $home, self::CACHE_FILE_PATH);
        $dir = dirname($path);
        $fileSystem = $this->getFileSystem();

        // Ensure cache directory exists
        if (! $fileSystem->isDir($dir)) {
            $fileSystem->mkdir($dir, 0755, true);
        }

        return $path;
    }

    /**
     * @return array{latest_version: string|null, timestamp: int}|null
     */
    protected function readCache(string $cachePath): ?array
    {
        $fileSystem = $this->getFileSystem();

        if (! $fileSystem->fileExists($cachePath)) {
            return null;
        }

        try {
            $content = $fileSystem->read($cachePath);
        } catch (\RuntimeException $e) {
            return null;
        }

        $data = @json_decode($content, true);
        if (! is_array($data) || ! isset($data['timestamp'])) {
            return null;
        }

        /** @var array{latest_version: string|null, timestamp: int} $data */
        return $data;
    }

    /**
     * @param array{latest_version: string|null, timestamp: int} $cacheData
     */
    protected function isCacheFresh(array $cacheData): bool
    {
        $cacheTimestamp = $cacheData['timestamp'];
        $age = time() - $cacheTimestamp;

        return $age < self::CACHE_TTL_SECONDS;
    }

    protected function fetchLatestVersionFromGitHub(): ?string
    {
        try {
            $githubProvider = $this->createGithubProvider();
            $release = $githubProvider->getLatestRelease();

            return ltrim($release['tag_name'] ?? '', 'v');
        } catch (\Exception $e) {
            // Fail silently - don't block the user's command
            return null;
        }
    }

    protected function createGithubProvider(): GithubProvider
    {
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'stud-cli',
        ];

        if ($this->gitToken) {
            $headers['Authorization'] = 'Bearer ' . $this->gitToken;
        }

        $client = $this->httpClient ?? HttpClient::createForBaseUri('https://api.github.com', [
            'headers' => $headers,
        ]);

        return new GithubProvider($this->gitToken ?? '', $this->repoOwner, $this->repoName, $client);
    }

    protected function writeCache(string $cachePath, ?string $latestVersion): void
    {
        $data = [
            'latest_version' => $latestVersion,
            'timestamp' => time(),
        ];

        $fileSystem = $this->getFileSystem();
        $encoded = json_encode($data, JSON_PRETTY_PRINT);
        // @codeCoverageIgnoreStart
        // json_encode returning false is extremely rare (only with circular references or invalid UTF-8)
        // and is difficult to test in isolation
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode cache data');
        }
        // @codeCoverageIgnoreEnd
        $fileSystem->filePutContents($cachePath, $encoded);
    }

    protected function isNewerVersion(string $latestVersion): bool
    {
        $latest = ltrim($latestVersion, 'v');
        $current = ltrim($this->currentVersion, 'v');

        return version_compare($latest, $current, '>');
    }

    /**
     * Gets the FileSystem instance, creating one if not provided.
     */
    private function getFileSystem(): FileSystem
    {
        return $this->fileSystem ?? FileSystem::createLocal();
    }
}
