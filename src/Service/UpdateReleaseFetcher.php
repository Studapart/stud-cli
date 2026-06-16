<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Exception\ApiException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UpdateReleaseFetcher
{
    public function __construct(
        private readonly string $repoOwner,
        private readonly string $repoName,
        private readonly string $currentVersion,
        private readonly FileSystem $fileSystem,
        private readonly ?string $gitToken = null,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * @codeCoverageIgnore
     */
    public function createGithubProvider(): GithubProvider
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

    /**
     * @return array{release: array<string, mixed>|null, is404: bool}
     *
     * @codeCoverageIgnore
     */
    public function fetchLatestRelease(GithubProvider $githubProvider, WorkflowEntryRecorder $recorder): array
    {
        try {
            return ['release' => $githubProvider->getLatestRelease(), 'is404' => false];
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 404) {
                $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.warning_no_releases'));

                return ['release' => null, 'is404' => true];
            }

            $recorder->addErrorWithDetails(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('update.error_fetch', ['error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return ['release' => null, 'is404' => false];
        } catch (\Exception $e) {
            // @codeCoverageIgnoreStart
            if (str_contains($e->getMessage(), 'Status: 404')) {
                $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.warning_no_releases'));

                return ['release' => null, 'is404' => true];
            }

            $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.error_fetch', ['error' => $e->getMessage()]));

            return ['release' => null, 'is404' => false];
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @return int|array<string, mixed>
     */
    public function getReleaseOrExitCode(GithubProvider $githubProvider, WorkflowEntryRecorder $recorder): int|array
    {
        $releaseResult = $this->fetchLatestRelease($githubProvider, $recorder);
        if ($releaseResult['is404']) {
            return 0;
        }
        if ($releaseResult['release'] === null) {
            return 1;
        }
        if ($this->isAlreadyLatestVersion($releaseResult['release'], $recorder)) {
            return 0;
        }

        return $releaseResult['release'];
    }

    /**
     * @param array<string, mixed> $release
     *
     * @codeCoverageIgnore
     */
    public function isAlreadyLatestVersion(array $release, WorkflowEntryRecorder $recorder): bool
    {
        $latestVersion = ltrim($release['tag_name'] ?? '', 'v');
        $currentVersion = ltrim($this->currentVersion, 'v');

        if (version_compare($latestVersion, $currentVersion, '<=')) {
            $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.success_latest', ['version' => $this->currentVersion]));

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $release
     *
     * @return array<string, mixed>|null
     *
     * @codeCoverageIgnore
     */
    public function findPharAsset(array $release, WorkflowEntryRecorder $recorder): ?array
    {
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.new_version', ['version' => $release['tag_name'] ?? 'unknown']));

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

        $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.error_no_phar', ['assets' => implode(', ', array_column($release['assets'] ?? [], 'name'))]));

        return null;
    }

    /**
     * @param array<string, mixed> $pharAsset
     *
     * @codeCoverageIgnore
     */
    public function downloadPhar(array $pharAsset, WorkflowEntryRecorder $recorder, callable $logVerbose): ?string
    {
        $tempFile = sys_get_temp_dir() . '/stud.phar.new';

        $assetId = $pharAsset['id'] ?? null;
        if (! $assetId) {
            $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.error_asset_id'));

            return null;
        }

        $apiUrl = "https://api.github.com/repos/{$this->repoOwner}/{$this->repoName}/releases/assets/{$assetId}";
        $logVerbose('Downloading from', $apiUrl);

        try {
            $headers = [
                'User-Agent' => 'stud-cli',
                'Accept' => 'application/octet-stream',
            ];

            if ($this->gitToken) {
                $headers['Authorization'] = 'Bearer ' . $this->gitToken;
            }

            // @codeCoverageIgnoreStart
            $downloadClient = $this->httpClient ?? HttpClient::create([
                'headers' => $headers,
            ]);
            // @codeCoverageIgnoreEnd

            $response = $downloadClient->request('GET', $apiUrl);
            $this->fileSystem->filePutContents($tempFile, $response->getContent());

            return $tempFile;
        } catch (\Exception $e) {
            // @codeCoverageIgnoreStart
            $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.error_download', ['error' => $e->getMessage()]));

            return null;
            // @codeCoverageIgnoreEnd
        }
    }
}
