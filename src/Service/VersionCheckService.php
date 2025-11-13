<?php

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
        private ?HttpClientInterface $httpClient = null
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

        // Ensure cache directory exists
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $path;
    }

    /**
     * @return array{latest_version: string|null, timestamp: int}|null
     */
    protected function readCache(string $cachePath): ?array
    {
        if (!file_exists($cachePath)) {
            return null;
        }

        $content = @file_get_contents($cachePath);
        if ($content === false) {
            return null;
        }

        $data = @json_decode($content, true);
        if (!is_array($data) || !isset($data['timestamp'])) {
            return null;
        }

        return $data;
    }

    protected function isCacheFresh(array $cacheData): bool
    {
        $cacheTimestamp = $cacheData['timestamp'] ?? 0;
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

        @file_put_contents($cachePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function isNewerVersion(string $latestVersion): bool
    {
        $latest = ltrim($latestVersion, 'v');
        $current = ltrim($this->currentVersion, 'v');
        return version_compare($latest, $current, '>');
    }
}

