<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

class GitRemoteUrlParser
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
    ) {
    }

    /**
     * @return array{owner?: string, name?: string, provider?: string}
     */
    public function parseRemote(string $remote = 'origin'): array
    {
        $remoteUrl = $this->getRemoteUrl($remote);
        if ($remoteUrl === null) {
            return [];
        }

        return $this->parseUrl($remoteUrl);
    }

    /**
     * @return array{owner?: string, name?: string, provider?: string}
     */
    public function parseUrl(string $remoteUrl): array
    {
        if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $remoteUrl, $matches)) {
            return [
                'owner' => $matches[1],
                'name' => $matches[2],
                'provider' => 'github',
            ];
        }

        if (preg_match('#gitlab\.com[:/](.+)/([^/]+?)(?:\.git)?$#', $remoteUrl, $matches)) {
            return [
                'owner' => $matches[1],
                'name' => $matches[2],
                'provider' => 'gitlab',
            ];
        }

        if (preg_match('#(?:git@|https?://)([^/:]+)[:/](.+)/([^/]+?)(?:\.git)?$#', $remoteUrl, $matches)) {
            $host = $matches[1];
            if ($host !== 'github.com') {
                return [
                    'owner' => $matches[2],
                    'name' => $matches[3],
                    'provider' => 'gitlab',
                ];
            }
        }

        return [];
    }

    public function getRemoteUrl(string $remote = 'origin'): ?string
    {
        $process = $this->runQuietly("git config --get remote.{$remote}.url");
        if (! $process->isSuccessful()) {
            return null;
        }

        $remoteUrl = trim($process->getOutput());
        $remoteUrl = rtrim($remoteUrl, '.');

        return $remoteUrl === '' ? null : $remoteUrl;
    }

    protected function runQuietly(string $command): Process
    {
        $process = $this->processFactory->create($command);
        $process->run();

        return $process;
    }
}
