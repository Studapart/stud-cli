<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\ChangelogParser;
use App\Service\GithubProvider;
use App\Service\Logger;
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
        protected ?HttpClientInterface $httpClient = null
    ) {
    }

    public function handle(SymfonyStyle $io, bool $info = false): int
    {
        $io->section($this->translator->trans('update.section'));

        $binaryPath = $this->updateFileService->getBinaryPath($this->binaryPath);
        $this->logVerbose($this->translator->trans('update.binary_path'), $binaryPath);
        $this->logVerbose($this->translator->trans('update.repository'), "{$this->repoOwner}/{$this->repoName}");
        $this->logVerbose($this->translator->trans('update.current_version'), $this->currentVersion);

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
        if (! $pharAsset) {
            return 1;
        }

        $tempFile = $this->downloadPhar($io, $pharAsset, $this->repoOwner, $this->repoName);
        if ($tempFile === null) {
            return 1;
        }

        // Verify the downloaded file's hash before proceeding
        $verificationResult = $this->updateFileService->verifyHash($io, $tempFile, $pharAsset);
        if ($verificationResult === false) {
            @unlink($tempFile);

            return 1;
        }

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

        $client = $this->httpClient ?? HttpClient::createForBaseUri('https://api.github.com', [
            'headers' => $headers,
        ]);

        return new GithubProvider($this->gitToken ?? '', $repoOwner, $repoName, $client);
    }

    /**
     * @return array{release: array<string, mixed>|null, is404: bool}
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

    /**
     * @param array<string, mixed> $release
     */
    protected function isAlreadyLatestVersion(SymfonyStyle $io, array $release): bool
    {
        $latestVersion = ltrim($release['tag_name'] ?? '', 'v');
        $currentVersion = ltrim($this->currentVersion, 'v');

        $this->logVerbose($this->translator->trans('update.latest_version'), $latestVersion);

        if (version_compare($latestVersion, $currentVersion, '<=')) {
            $io->success($this->translator->trans('update.success_latest', ['version' => $this->currentVersion]));

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>|null
     */
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

    /**
     * @param array<string, mixed> $pharAsset
     */
    protected function downloadPhar(SymfonyStyle $io, array $pharAsset, string $repoOwner, string $repoName): ?string
    {
        $tempFile = sys_get_temp_dir() . '/stud.phar.new';

        // Extract asset ID from the asset object
        $assetId = $pharAsset['id'] ?? null;
        if (! $assetId) {
            $io->error(explode("\n", $this->translator->trans('update.error_asset_id')));

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

    protected function logVerbose(string $label, string $value): void
    {
        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$label}: {$value}</>");
    }

    /**
     * @param array<string, mixed> $release
     */
    protected function displayChangelog(SymfonyStyle $io, GithubProvider $githubProvider, array $release): void
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

            $io->section($this->translator->trans('update.changelog_section', ['version' => $tagName]));

            // Display breaking changes first with warning
            if ($hasBreaking) {
                $io->warning($this->translator->trans('update.breaking_changes_detected'));
                foreach ($changes['breakingChanges'] as $breakingChange) {
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
            $this->logVerbose($this->translator->trans('update.changelog_error'), $e->getMessage());
        }
    }
}
