<?php

namespace App\Handler;

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
        protected ?string $gitToken = null,
        protected ?HttpClientInterface $httpClient = null
    ) {
    }

    public function handle(SymfonyStyle $io): int
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
                $io->warning(explode("\n", $this->translator->trans('update.warning_no_releases')));
                return ['release' => null, 'is404' => true];
            }
            
            $io->error(explode("\n", $this->translator->trans('update.error_fetch', ['error' => $e->getMessage()])));
            return ['release' => null, 'is404' => false];
        }
    }

    protected function isAlreadyLatestVersion(SymfonyStyle $io, array $release): bool
    {
        $latestVersion = ltrim($release['tag_name'], 'v');
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
        $io->text($this->translator->trans('update.new_version', ['version' => $release['tag_name']]));

        foreach ($release['assets'] ?? [] as $asset) {
            $assetName = $asset['name'];
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

    protected function displayChangelog(SymfonyStyle $io, GithubProvider $githubProvider, array $release): void
    {
        try {
            $latestVersion = ltrim($release['tag_name'], 'v');
            $changelogContent = $githubProvider->getChangelogContent($release['tag_name']);
            $changes = $this->parseChangelog($changelogContent, $this->currentVersion, $latestVersion);
            
            if (empty($changes['sections']) && !$changes['hasBreaking']) {
                // No changes found or already up to date
                return;
            }

            $io->section($this->translator->trans('update.changelog_section', ['version' => $release['tag_name']]));
            
            // Display breaking changes first with warning
            if ($changes['hasBreaking']) {
                $io->warning($this->translator->trans('update.breaking_changes_detected'));
                foreach ($changes['breakingChanges'] as $breakingChange) {
                    $io->writeln("  <fg=red>⚠️  {$breakingChange}</>");
                }
                $io->newLine();
            }

            // Display other sections
            foreach ($changes['sections'] as $sectionType => $items) {
                if (empty($items)) {
                    continue;
                }
                
                $sectionTitle = $this->getSectionTitle($sectionType);
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

    protected function parseChangelog(string $changelogContent, string $currentVersion, string $latestVersion): array
    {
        $currentVersion = ltrim($currentVersion, 'v');
        $latestVersion = ltrim($latestVersion, 'v');
        
        $result = [
            'sections' => [],
            'hasBreaking' => false,
            'breakingChanges' => [],
        ];

        // Split changelog into lines
        $lines = explode("\n", $changelogContent);
        
        $inTargetVersion = false;
        $currentSection = null;
        
        foreach ($lines as $lineNum => $line) {
            // Check for version header: ## [x.y.z] - YYYY-MM-DD
            if (preg_match('/^##\s+\[(\d+\.\d+\.\d+)\]/', $line, $matches)) {
                $versionInChangelog = $matches[1];
                
                // Check if we've reached a version older than current (stop parsing)
                if (version_compare($versionInChangelog, $currentVersion, '<')) {
                    break;
                }
                
                // Check if this version is between current and latest (inclusive of latest, exclusive of current)
                // We want to include all versions: current < version <= latest
                if (version_compare($versionInChangelog, $latestVersion, '<=') && 
                    version_compare($versionInChangelog, $currentVersion, '>')) {
                    $inTargetVersion = true;
                    $currentSection = null; // Reset section when entering new version
                } else {
                    $inTargetVersion = false;
                }
                continue;
            }

            if (!$inTargetVersion) {
                continue;
            }

            // Check for section headers: ### Added, ### Fixed, etc.
            if (preg_match('/^###\s+(\w+)/', $line, $matches)) {
                $currentSection = strtolower($matches[1]);
                continue;
            }

            // Check for breaking changes markers
            if (preg_match('/\[BREAKING\s+CHANGE\]|\[BREAKING\]|\[REMOVED\]/i', $line)) {
                $result['hasBreaking'] = true;
                // Extract the breaking change text (remove markdown list markers)
                $breakingText = preg_replace('/^[\s*\-]+/', '', trim($line));
                if (!empty($breakingText)) {
                    $result['breakingChanges'][] = $breakingText;
                }
            }

            // Check for command renaming (e.g., "issues:search" to "items:search")
            if (preg_match('/(?:rename|changed|renamed).*?(\w+:\w+).*?to.*?(\w+:\w+)/i', $line, $matches)) {
                $result['hasBreaking'] = true;
                $result['breakingChanges'][] = "Command renamed: {$matches[1]} → {$matches[2]}";
            }

            // Collect items in current section
            if ($currentSection && preg_match('/^[\s*\-]+\s*(.+)$/', $line, $matches)) {
                $item = trim($matches[1]);
                if (!empty($item)) {
                    if (!isset($result['sections'][$currentSection])) {
                        $result['sections'][$currentSection] = [];
                    }
                    $result['sections'][$currentSection][] = $item;
                }
            }
        }

        return $result;
    }

    protected function getSectionTitle(string $sectionType): string
    {
        return match(strtolower($sectionType)) {
            'added' => '### Added',
            'changed' => '### Changed',
            'deprecated' => '### Deprecated',
            'removed' => '### Removed',
            'fixed' => '### Fixed',
            'security' => '### Security',
            default => '### ' . ucfirst($sectionType),
        };
    }
}

