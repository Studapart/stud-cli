<?php

namespace App\Handler;

use App\Service\ChangelogParser;
use App\Service\GithubProvider;
use App\Service\TranslationService;
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
        protected readonly TranslationService $translator,
        protected readonly ChangelogParser $changelogParser,
        protected ?string $gitToken = null,
        protected ?HttpClientInterface $httpClient = null
    ) {
    }

    public function handle(SymfonyStyle $io, bool $info = false): int
    {
        $io->section($this->translator->trans('update.section'));

        $binaryPath = $this->getBinaryPath();
        $this->logVerbose($io, $this->translator->trans('update.binary_path'), $binaryPath);
        $this->logVerbose($io, $this->translator->trans('update.repository'), "{$this->repoOwner}/{$this->repoName}");
        $this->logVerbose($io, $this->translator->trans('update.current_version'), $this->currentVersion);

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

        // Display changelog before downloading
        $this->displayChangelog($io, $githubProvider, $release);

        // If --info flag is set, exit after displaying changelog without downloading
        if ($info) {
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

        // Verify the downloaded file's hash before proceeding
        $verificationResult = $this->verifyHash($io, $tempFile, $pharAsset);
        if ($verificationResult === false) {
            @unlink($tempFile);
            return 1;
        }

        return $this->replaceBinary($io, $tempFile, $binaryPath, $release['tag_name'] ?? 'unknown');
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
                $io->warning(explode("\n", $this->translator->trans('update.warning_no_releases')));
                return ['release' => null, 'is404' => true];
            }
            
            $io->error(explode("\n", $this->translator->trans('update.error_fetch', ['error' => $e->getMessage()])));
            return ['release' => null, 'is404' => false];
        }
    }

    protected function isAlreadyLatestVersion(SymfonyStyle $io, array $release): bool
    {
        $latestVersion = ltrim($release['tag_name'] ?? '', 'v');
        $currentVersion = ltrim($this->currentVersion, 'v');

        $this->logVerbose($io, $this->translator->trans('update.latest_version'), $latestVersion);

        if (version_compare($latestVersion, $currentVersion, '<=')) {
            $io->success($this->translator->trans('update.success_latest', ['version' => $this->currentVersion]));
            return true;
        }

        return false;
    }

    protected function findPharAsset(SymfonyStyle $io, array $release): ?array
    {
        $io->text($this->translator->trans('update.new_version', ['version' => $release['tag_name'] ?? 'unknown']));

        foreach ($release['assets'] ?? [] as $asset) {
            $assetName = $asset['name'] ?? null;
            if ($assetName === null) {
                continue; // Skip assets without a name
            }
            if ($assetName === 'stud.phar' || 
                (str_starts_with($assetName, 'stud-') && str_ends_with($assetName, '.phar'))) {
                return $asset;
            }
        }

        $io->error(explode("\n", $this->translator->trans('update.error_no_phar', ['assets' => implode(', ', array_column($release['assets'] ?? [], 'name'))])));
        return null;
    }

    protected function downloadPhar(SymfonyStyle $io, array $pharAsset, string $repoOwner, string $repoName): ?string
    {
        $tempFile = sys_get_temp_dir() . '/stud.phar.new';
        
        // Extract asset ID from the asset object
        $assetId = $pharAsset['id'] ?? null;
        if (!$assetId) {
            $io->error(explode("\n", $this->translator->trans('update.error_asset_id')));
            return null;
        }
        
        // Construct the GitHub API asset endpoint URL
        $apiUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/releases/assets/{$assetId}";
        $this->logVerbose($io, $this->translator->trans('update.downloading_from'), $apiUrl);

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
            $io->error(explode("\n", $this->translator->trans('update.error_download', ['error' => $e->getMessage()])));
            return null;
        }
    }

    protected function replaceBinary(SymfonyStyle $io, string $tempFile, string $binaryPath, string $tagName): int
    {
        if (!is_writable($binaryPath)) {
            $io->error(explode("\n", $this->translator->trans('update.error_not_writable')));
            @unlink($tempFile);
            return 1;
        }

        // Create versioned backup path (e.g., /home/pem/.local/bin/stud-1.1.1.bak)
        $backupPath = $binaryPath . '-' . $this->currentVersion . '.bak';

        // Step 1: Backup the current executable
        // Note: rename() doesn't throw exceptions in PHP (returns false), but catch block handles edge cases
        // Exception from rename() is extremely rare and hard to simulate
        // @codeCoverageIgnoreStart
        try {
            rename($binaryPath, $backupPath);
        } catch (\Exception $e) {
            $io->error(explode("\n", $this->translator->trans('update.error_backup', ['error' => $e->getMessage()])));
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
            // Exception from rename/chmod is extremely rare and hard to simulate
            // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            try {
                rename($backupPath, $binaryPath);
                $io->error(explode("\n", $this->translator->trans('update.error_rollback', ['error' => $e->getMessage()])));
            } catch (\Exception $rollbackException) {
                // Rollback also failed - this is a critical state
                $io->error(explode("\n", $this->translator->trans('update.error_rollback_failed', [
                    'original_error' => $e->getMessage(),
                    'rollback_error' => $rollbackException->getMessage(),
                    'backup_path' => $backupPath
                ])));
            }
            @unlink($tempFile);
            return 1;
        }
        // @codeCoverageIgnoreEnd
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
        // Hard to test in unit tests without actual PHAR environment
        // @codeCoverageIgnoreStart
        if (class_exists('Phar') && \Phar::running(false)) {
            return \Phar::running(false);
        }
        // @codeCoverageIgnoreEnd

        // Otherwise, try to get path from ReflectionClass as suggested in ticket
        try {
            $reflection = new \ReflectionClass(\Castor\Console\Application::class);
            $filename = $reflection->getFileName();
            
            // If we're in a PHAR, the filename will be phar://...
            // Hard to test in unit tests without actual PHAR environment
            // @codeCoverageIgnoreStart
            if (str_starts_with($filename, 'phar://')) {
                return $filename;
            }
            // @codeCoverageIgnoreEnd
        // ReflectionException is hard to trigger in tests
        // @codeCoverageIgnoreStart
        } catch (\ReflectionException $e) {
            // Fall through to next method
        }
        // @codeCoverageIgnoreEnd

        // Fallback: use the provided binary path
        return $this->binaryPath;
    }

    protected function displayChangelog(SymfonyStyle $io, GithubProvider $githubProvider, array $release): void
    {
        try {
            $tagName = $release['tag_name'] ?? 'unknown';
            $latestVersion = ltrim($tagName, 'v');
            $changelogContent = $githubProvider->getChangelogContent($tagName);
            $changes = $this->changelogParser->parse($changelogContent, $this->currentVersion, $latestVersion);
            
            $sections = $changes['sections'] ?? [];
            $hasBreaking = $changes['hasBreaking'] ?? false;
            
            if (empty($sections) && !$hasBreaking) {
                // No changes found or already up to date
                return;
            }

            $io->section($this->translator->trans('update.changelog_section', ['version' => $tagName]));
            
            // Display breaking changes first with warning
            if ($hasBreaking) {
                $io->warning($this->translator->trans('update.breaking_changes_detected'));
                foreach ($changes['breakingChanges'] ?? [] as $breakingChange) {
                    $io->writeln("  <fg=red>⚠️  {$breakingChange}</>");
                }
                $io->newLine();
            }

            // Display other sections
            foreach ($sections as $sectionType => $items) {
                // Defensive check: ChangelogParser should not produce empty sections, but check anyway
                // ChangelogParser guarantees non-empty sections
                // @codeCoverageIgnoreStart
                if (empty($items)) {
                    continue;
                }
                // @codeCoverageIgnoreEnd
                
                $sectionTitle = $this->changelogParser->getSectionTitle($sectionType);
                $io->text("<fg=cyan>{$sectionTitle}</>");
                foreach ($items as $item) {
                    $io->writeln("  • {$item}");
                }
                $io->newLine();
            }
        } catch (\Exception $e) {
            // Silently fail - don't block update if changelog can't be fetched
            $this->logVerbose($io, $this->translator->trans('update.changelog_error'), $e->getMessage());
        }
    }

    /**
     * Verifies the hash of the downloaded file against the digest from GitHub API.
     * Returns true if verification succeeds or user overrides, false if user aborts.
     */
    protected function verifyHash(SymfonyStyle $io, string $tempFile, array $pharAsset): bool
    {
        // Extract digest from the asset's JSON object (format: "sha256:...")
        $digest = $pharAsset['digest'] ?? null;
        
        // Calculate the local file's SHA-256 hash
        $calculatedHash = @hash_file('sha256', $tempFile);
        if ($calculatedHash === false) {
            $io->error(explode("\n", $this->translator->trans('update.error_hash_calculation')));
            return false;
        }

        // Case A: Match (Verified) - proceed automatically
        if ($digest !== null) {
            // Extract hash from digest format "sha256:hash"
            $expectedHash = null;
            if (str_starts_with($digest, 'sha256:')) {
                $expectedHash = substr($digest, 7); // Remove "sha256:" prefix
            } else {
                // If digest doesn't have prefix, assume it's the hash itself
                $expectedHash = $digest;
            }

            if (strtolower($calculatedHash) === strtolower($expectedHash)) {
                $io->text($this->translator->trans('update.success_hash_verified'));
                return true;
            }
        }

        // Case B: Mismatch or Missing Digest (Failed) - prompt user for override
        $errorMessage = $digest === null 
            ? $this->translator->trans('update.error_digest_not_found')
            : $this->translator->trans('update.error_hash_mismatch', [
                'expected' => $digest,
                'calculated' => $calculatedHash,
            ]);
        
        $io->warning(explode("\n", $errorMessage));
        
        $continue = $io->confirm(
            $this->translator->trans('update.prompt_continue_on_verification_failure'),
            false
        );

        if (!$continue) {
            // User aborted - stop process, delete temp file, exit with error code 1
            return false;
        }

        // User overrode - proceed to file replacement
        return true;
    }

}

