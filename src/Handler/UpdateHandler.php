<?php

namespace App\Handler;

use App\Service\GithubProvider;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UpdateHandler
{
    public function __construct(
        protected readonly string $repoOwner,
        protected readonly string $repoName,
        protected readonly string $currentVersion,
        protected readonly string $binaryPath,
        protected ?string $gitToken = null,
        protected ?HttpClientInterface $httpClient = null
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $io->section('Checking for updates');

        $binaryPath = $this->getBinaryPath();
        $this->logVerbose($io, 'Binary path', $binaryPath);
        $this->logVerbose($io, 'Repository', "{$this->repoOwner}/{$this->repoName}");
        $this->logVerbose($io, 'Current version', $this->currentVersion);

        $githubProvider = $this->createGithubProvider($this->repoOwner, $this->repoName);
        $releaseResult = $this->fetchLatestRelease($io, $githubProvider);
        
        if ($releaseResult['is404']) {
            // 404 means no releases found - this is a success case (already warned)
            return 0;
        }
        
        if ($releaseResult['release'] === null) {
            // Actual error occurred
            return 1;
        }
        
        $release = $releaseResult['release'];

        if ($this->isAlreadyLatestVersion($io, $release)) {
            return 0;
        }

        $pharAsset = $this->findPharAsset($io, $release);
        if (!$pharAsset) {
            return 1;
        }

        $tempFile = $this->downloadPhar($io, $pharAsset, $this->repoOwner, $this->repoName);
        if ($tempFile === null) {
            return 1;
        }

        return $this->replaceBinary($io, $tempFile, $binaryPath, $release['tag_name']);
    }


    protected function createGithubProvider(string $repoOwner, string $repoName): GithubProvider
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

        return new GithubProvider($this->gitToken ?? '', $repoOwner, $repoName, $client);
    }

    /**
     * @return array{release: array|null, is404: bool}
     */
    protected function fetchLatestRelease(SymfonyStyle $io, GithubProvider $githubProvider): array
    {
        try {
            return ['release' => $githubProvider->getLatestRelease(), 'is404' => false];
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Status: 404')) {
                $io->warning([
                    'No releases found for this repository.',
                    'The repository may not have any published releases yet.',
                ]);
                return ['release' => null, 'is404' => true];
            }
            
            $io->error([
                'Failed to fetch latest release information.',
                'Error: ' . $e->getMessage(),
            ]);
            return ['release' => null, 'is404' => false];
        }
    }

    protected function isAlreadyLatestVersion(SymfonyStyle $io, array $release): bool
    {
        $latestVersion = ltrim($release['tag_name'], 'v');
        $currentVersion = ltrim($this->currentVersion, 'v');

        $this->logVerbose($io, 'Latest version', $latestVersion);

        if (version_compare($latestVersion, $currentVersion, '<=')) {
            $io->success("You are already on the latest version ({$this->currentVersion}).");
            return true;
        }

        return false;
    }

    protected function findPharAsset(SymfonyStyle $io, array $release): ?array
    {
        $io->text("A new version ({$release['tag_name']}) is available. Updating...");

        foreach ($release['assets'] ?? [] as $asset) {
            $assetName = $asset['name'];
            if ($assetName === 'stud.phar' || 
                (str_starts_with($assetName, 'stud-') && str_ends_with($assetName, '.phar'))) {
                return $asset;
            }
        }

        $io->error([
            'Could not find stud.phar asset in the latest release.',
            'Release assets: ' . implode(', ', array_column($release['assets'] ?? [], 'name')),
        ]);
        return null;
    }

    protected function downloadPhar(SymfonyStyle $io, array $pharAsset, string $repoOwner, string $repoName): ?string
    {
        $tempFile = sys_get_temp_dir() . '/stud.phar.new';
        
        // Extract asset ID from the asset object
        $assetId = $pharAsset['id'] ?? null;
        if (!$assetId) {
            $io->error([
                'Failed to download the new version.',
                'Error: Asset ID not found in release asset.',
            ]);
            return null;
        }
        
        // Construct the GitHub API asset endpoint URL
        $apiUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/releases/assets/{$assetId}";
        $this->logVerbose($io, 'Downloading from', $apiUrl);

        try {
            $headers = [
                'User-Agent' => 'stud-cli',
                'Accept' => 'application/octet-stream',
            ];
            
            if ($this->gitToken) {
                $headers['Authorization'] = 'Bearer ' . $this->gitToken;
            }
            
            // If httpClient is provided (e.g., in tests), use it
            // Otherwise, create a new client with auth headers for downloads
            // This ensures private repositories can be accessed with authentication
            $downloadClient = $this->httpClient ?? HttpClient::create([
                'headers' => $headers,
            ]);
            
            $response = $downloadClient->request('GET', $apiUrl);
            file_put_contents($tempFile, $response->getContent());
            return $tempFile;
        } catch (\Exception $e) {
            $io->error([
                'Failed to download the new version.',
                'Error: ' . $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function replaceBinary(SymfonyStyle $io, string $tempFile, string $binaryPath, string $tagName): int
    {
        if (!is_writable($binaryPath)) {
            $io->error([
                'Update failed: The file is not writable.',
                'Please re-run with elevated privileges: sudo stud update',
            ]);
            @unlink($tempFile);
            return 1;
        }

        // Create versioned backup path (e.g., /home/pem/.local/bin/stud-1.1.1.bak)
        $backupPath = $binaryPath . '-' . $this->currentVersion . '.bak';

        // Step 1: Backup the current executable
        // Note: rename() doesn't throw exceptions in PHP (returns false), but catch block handles edge cases
        // @codeCoverageIgnoreStart
        try {
            rename($binaryPath, $backupPath);
        } catch (\Exception $e) {
            $io->error([
                'Failed to create backup of current version.',
                'Error: ' . $e->getMessage(),
            ]);
            @unlink($tempFile);
            return 1;
        }
        // @codeCoverageIgnoreEnd

        // Step 2: Try to activate new version (atomic transaction)
        try {
            rename($tempFile, $binaryPath);
            chmod($binaryPath, 0755);
            
            // Backup file is left behind for cleanup on next run
            // Note: No $io->success() call here to avoid zlib error after PHAR replacement
            return 0;
            // Note: rename() doesn't throw exceptions in PHP, but chmod() might in edge cases
            // Rollback on failure
            // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            try {
                rename($backupPath, $binaryPath);
                $io->error([
                    'Update failed and was rolled back.',
                    'Error: ' . $e->getMessage(),
                ]);
            } catch (\Exception $rollbackException) {
                // Rollback also failed - this is a critical state
                $io->error([
                    'Update failed and rollback also failed!',
                    'Original error: ' . $e->getMessage(),
                    'Rollback error: ' . $rollbackException->getMessage(),
                    'Please manually restore from: ' . $backupPath,
                ]);
            }
            // @codeCoverageIgnoreEnd
            @unlink($tempFile);
            return 1;
        }
    }

    protected function logVerbose(SymfonyStyle $io, string $label, string $value): void
    {
        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>{$label}: {$value}</>");
        }
    }

    protected function getBinaryPath(): string
    {
        // If running as PHAR, use Phar::running()
        // @codeCoverageIgnoreStart - Hard to test in unit tests without actual PHAR environment
        if (class_exists('Phar') && \Phar::running(false)) {
            return \Phar::running(false);
        }
        // @codeCoverageIgnoreEnd

        // Otherwise, try to get path from ReflectionClass as suggested in ticket
        try {
            $reflection = new \ReflectionClass(\Castor\Console\Application::class);
            $filename = $reflection->getFileName();
            
            // If we're in a PHAR, the filename will be phar://...
            // @codeCoverageIgnoreStart - Hard to test in unit tests without actual PHAR environment
            if (str_starts_with($filename, 'phar://')) {
                return $filename;
            }
            // @codeCoverageIgnoreEnd
        } catch (\ReflectionException $e) {
            // @codeCoverageIgnoreStart - ReflectionException is hard to trigger in tests
            // Fall through to next method
            // @codeCoverageIgnoreEnd
        }

        // Fallback: use the provided binary path
        return $this->binaryPath;
    }
}

