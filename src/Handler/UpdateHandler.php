<?php

declare(strict_types=1);

namespace App\Handler;

use App\Exception\ApiException;
use App\Service\ChangelogParser;
use App\Service\FileSystem;
use App\Service\GithubProvider;
use App\Service\Logger;
use App\Service\MigrationExecutor;
use App\Service\MigrationRegistry;
use App\Service\TranslationService;
use App\Service\UpdateFileService;
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
        protected readonly UpdateFileService $updateFileService,
        protected readonly Logger $logger,
        protected ?string $gitToken = null,
        protected ?HttpClientInterface $httpClient = null,
        protected readonly ?FileSystem $fileSystem = null
    ) {
    }

    private function getFileSystem(): FileSystem
    {
        return $this->fileSystem ?? FileSystem::createLocal();
    }

    public function handle(SymfonyStyle $io, bool $info = false): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('update.section'));

        $binaryPath = $this->updateFileService->getBinaryPath($this->binaryPath);
        $this->logVerbose($this->translator->trans('update.binary_path'), $binaryPath);
        $this->logVerbose($this->translator->trans('update.repository'), "{$this->repoOwner}/{$this->repoName}");
        $this->logVerbose($this->translator->trans('update.current_version'), $this->currentVersion);

        $githubProvider = $this->createGithubProvider($this->repoOwner, $this->repoName);
        $releaseResult = $this->fetchLatestRelease($githubProvider);

        if ($releaseResult['is404']) {
            // 404 means no releases found - this is a success case (already warned)
            return 0;
        }

        if ($releaseResult['release'] === null) {
            return 1;
        }

        $release = $releaseResult['release'];

        if ($this->isAlreadyLatestVersion($release)) {
            return 0;
        }

        // Display changelog before downloading
        $this->displayChangelog($githubProvider, $release);

        // If --info flag is set, exit after displaying changelog without downloading
        if ($info) {
            return 0;
        }

        $pharAsset = $this->findPharAsset($release);
        if (! $pharAsset) {
            return 1;
        }

        $tempFile = $this->downloadPhar($pharAsset, $this->repoOwner, $this->repoName);
        if ($tempFile === null) {
            return 1;
        }

        // Verify the downloaded file's hash before proceeding
        $verificationResult = $this->updateFileService->verifyHash($io, $tempFile, $pharAsset);
        if ($verificationResult === false) {
            $fileSystem = $this->getFileSystem();
            $fileSystem->delete($tempFile);

            return 1;
        }

        // Run prerequisite global migrations before binary replacement
        $migrationResult = $this->runPrerequisiteMigrations($io);
        // @codeCoverageIgnoreStart
        // Testing this cleanup path requires a real prerequisite migration failure scenario
        // which is difficult to test with in-memory filesystem due to MigrationRegistry's hardcoded paths
        if ($migrationResult !== 0) {
            $fileSystem = $this->getFileSystem();
            $fileSystem->delete($tempFile);

            return $migrationResult;
        }
        // @codeCoverageIgnoreEnd

        return $this->updateFileService->replaceBinary($io, $tempFile, $binaryPath, $this->currentVersion, $release['tag_name'] ?? 'unknown');
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

        // @codeCoverageIgnoreStart
        // HttpClient::createForBaseUri is a factory method that creates a real HTTP client
        // Testing this requires actual network calls, which is not feasible in unit tests
        $client = $this->httpClient ?? HttpClient::createForBaseUri('https://api.github.com', [
            'headers' => $headers,
        ]);
        // @codeCoverageIgnoreEnd

        return new GithubProvider($this->gitToken ?? '', $repoOwner, $repoName, $client);
    }

    /**
     * @return array{release: array<string, mixed>|null, is404: bool}
     */
    protected function fetchLatestRelease(GithubProvider $githubProvider): array
    {
        try {
            return ['release' => $githubProvider->getLatestRelease(), 'is404' => false];
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 404) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('update.warning_no_releases')));

                return ['release' => null, 'is404' => true];
            }

            $this->logger->errorWithDetails(
                Logger::VERBOSITY_NORMAL,
                $this->translator->trans('update.error_fetch', ['error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return ['release' => null, 'is404' => false];
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Status: 404')) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('update.warning_no_releases')));

                return ['release' => null, 'is404' => true];
            }

            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('update.error_fetch', ['error' => $e->getMessage()])));

            return ['release' => null, 'is404' => false];
        }
    }

    /**
     * @param array<string, mixed> $release
     */
    protected function isAlreadyLatestVersion(array $release): bool
    {
        $latestVersion = ltrim($release['tag_name'] ?? '', 'v');
        $currentVersion = ltrim($this->currentVersion, 'v');

        $this->logVerbose($this->translator->trans('update.latest_version'), $latestVersion);

        if (version_compare($latestVersion, $currentVersion, '<=')) {
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('update.success_latest', ['version' => $this->currentVersion]));

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>|null
     */
    protected function findPharAsset(array $release): ?array
    {
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('update.new_version', ['version' => $release['tag_name'] ?? 'unknown']));

        foreach ($release['assets'] ?? [] as $asset) {
            $assetName = $asset['name'] ?? null;
            if ($assetName === null) {
                continue;
            }
            if ($assetName === 'stud.phar' ||
                (str_starts_with($assetName, 'stud-') && str_ends_with($assetName, '.phar'))) {
                return $asset;
            }
        }

        $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('update.error_no_phar', ['assets' => implode(', ', array_column($release['assets'] ?? [], 'name'))])));

        return null;
    }

    /**
     * @param array<string, mixed> $pharAsset
     */
    protected function downloadPhar(array $pharAsset, string $repoOwner, string $repoName): ?string
    {
        $tempFile = sys_get_temp_dir() . '/stud.phar.new';

        // Extract asset ID from the asset object
        $assetId = $pharAsset['id'] ?? null;
        if (! $assetId) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('update.error_asset_id')));

            return null;
        }

        // Construct the GitHub API asset endpoint URL
        $apiUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/releases/assets/{$assetId}";
        $this->logVerbose($this->translator->trans('update.downloading_from'), $apiUrl);

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
            // @codeCoverageIgnoreStart
            // HttpClient::create is a factory method that creates a real HTTP client
            // Testing this requires actual network calls, which is not feasible in unit tests
            $downloadClient = $this->httpClient ?? HttpClient::create([
                'headers' => $headers,
            ]);
            // @codeCoverageIgnoreEnd

            $response = $downloadClient->request('GET', $apiUrl);
            $fileSystem = $this->getFileSystem();
            $fileSystem->filePutContents($tempFile, $response->getContent());

            return $tempFile;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('update.error_download', ['error' => $e->getMessage()])));

            return null;
        }
    }

    protected function logVerbose(string $label, string $value): void
    {
        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$label}: {$value}</>");
    }

    /**
     * Runs prerequisite global migrations before binary replacement.
     * Prerequisite migrations that fail will prevent update completion.
     *
     * @return int 0 on success, 1 on failure
     */
    protected function runPrerequisiteMigrations(SymfonyStyle $io): int
    {
        try {
            $configPath = $this->getConfigPath();
            $fileSystem = $this->getFileSystem();

            if (! $fileSystem->fileExists($configPath)) {
                // Config doesn't exist, nothing to migrate
                return 0;
            }

            $config = $fileSystem->parseFile($configPath);
            $currentVersion = $config['migration_version'] ?? '0';

            $registry = new MigrationRegistry($this->logger, $this->translator);
            $globalMigrations = $registry->discoverGlobalMigrations();

            // Filter to only prerequisite migrations
            $prerequisiteMigrations = array_filter($globalMigrations, function ($migration) {
                return $migration->isPrerequisite();
            });

            $pendingPrerequisiteMigrations = $registry->getPendingMigrations($prerequisiteMigrations, $currentVersion);

            if (empty($pendingPrerequisiteMigrations)) {
                // No pending prerequisite migrations
                return 0;
            }

            // @codeCoverageIgnoreStart
            // Testing this path requires a real prerequisite migration file to exist in the filesystem
            // MigrationRegistry uses hardcoded paths that cannot be easily mocked with in-memory filesystem
            $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('migration.global.running'));
            $executor = new MigrationExecutor($this->logger, $fileSystem, $this->translator);
            $executor->executeMigrations($pendingPrerequisiteMigrations, $config, $configPath);
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('migration.global.complete'));

            return 0;
            // @codeCoverageIgnoreEnd
        } catch (\Throwable $e) {
            $this->logger->error(
                Logger::VERBOSITY_NORMAL,
                explode("\n", $this->translator->trans('migration.error', [
                    'id' => 'prerequisite',
                    'error' => $e->getMessage(),
                ]))
            );

            return 1;
        }
    }

    /**
     * Gets the global config file path.
     */
    protected function getConfigPath(): string
    {
        $home = $_SERVER['HOME'] ?? throw new \RuntimeException('Could not determine home directory.');

        return rtrim($home, '/') . '/.config/stud/config.yml';
    }

    /**
     * @param array<string, mixed> $release
     */
    protected function displayChangelog(GithubProvider $githubProvider, array $release): void
    {
        try {
            $tagName = $release['tag_name'] ?? 'unknown';
            $latestVersion = ltrim($tagName, 'v');
            $changelogContent = $githubProvider->getChangelogContent($tagName);
            $changes = $this->changelogParser->parse($changelogContent, $this->currentVersion, $latestVersion);

            $sections = $changes['sections'];
            $hasBreaking = $changes['hasBreaking'];

            if (empty($sections) && ! $hasBreaking) {
                // No changes found or already up to date
                return;
            }

            $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('update.changelog_section', ['version' => $tagName]));

            // Display breaking changes first with warning
            if ($hasBreaking) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('update.breaking_changes_detected'));
                foreach ($changes['breakingChanges'] as $breakingChange) {
                    $this->logger->writeln(Logger::VERBOSITY_NORMAL, "  <fg=red>⚠️  {$breakingChange}</>");
                }
                $this->logger->newLine(Logger::VERBOSITY_NORMAL);
            }

            // Display other sections
            foreach ($sections as $sectionType => $items) {
                // Defensive check: ChangelogParser should not produce empty sections, but check anyway
                // ChangelogParser guarantees non-empty sections
                // @codeCoverageIgnoreStart
                // Empty sections are defensive checks that ChangelogParser should never produce
                if (empty($items)) {
                    continue;
                }
                // @codeCoverageIgnoreEnd

                $sectionTitle = $this->changelogParser->getSectionTitle($sectionType);
                $this->logger->text(Logger::VERBOSITY_NORMAL, "<fg=cyan>{$sectionTitle}</>");
                foreach ($items as $item) {
                    $this->logger->writeln(Logger::VERBOSITY_NORMAL, "  • {$item}");
                }
                $this->logger->newLine(Logger::VERBOSITY_NORMAL);
            }
        } catch (ApiException $e) {
            $this->logVerbose($this->translator->trans('update.changelog_error'), $e->getMessage());
            $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->logVerbose($this->translator->trans('update.changelog_error'), $e->getMessage());
        }
    }
}
