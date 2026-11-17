<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\HttpClientDefaults;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ReleaseAssetDownloader
{
    public function __construct(
        private readonly UpdateRepositoryContext $repository,
        private readonly FileSystem $fileSystem,
        private readonly ?HttpClientInterface $httpClient,
    ) {
    }

    public function assetUrl(int|string $assetId): string
    {
        return "https://api.github.com/repos/{$this->repository->owner}/{$this->repository->name}/releases/assets/{$assetId}";
    }

    /**
     * Downloads the asset binary into the system temp directory and returns the written path.
     */
    public function download(string $apiUrl): string
    {
        $tempFile = sys_get_temp_dir() . '/stud.phar.' . bin2hex(random_bytes(8));

        $headers = [
            'User-Agent' => 'stud-cli',
            'Accept' => 'application/octet-stream',
        ];

        if ($this->repository->token) {
            $headers['Authorization'] = 'Bearer ' . $this->repository->token;
        }

        // @codeCoverageIgnoreStart
        $downloadClient = $this->httpClient ?? HttpClient::create(HttpClientDefaults::withTransferLimits([
            'headers' => $headers,
        ]));
        // @codeCoverageIgnoreEnd

        $response = $downloadClient->request('GET', $apiUrl);
        $this->fileSystem->filePutContents($tempFile, $response->getContent());

        return $tempFile;
    }
}
