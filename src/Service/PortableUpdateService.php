<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PortableUpdateService
{
    public function __construct(
        protected readonly UpdateRepositoryContext $repository,
        mixed $translator,
        protected readonly WorkflowOutput $logger,
        protected readonly ?HttpClientInterface $httpClient = null,
    ) {
        unset($translator);
    }

    /**
     * Installs and activates a complete portable bundle for the current platform.
     *
     * @param array<string, mixed> $release
     */
    public function update(UpdateInstallContext $context, array $release, bool $quiet): int
    {
        if ($context->legacyPortableLayout) {
            return $this->fail('update.portable_legacy_layout');
        }

        $platform = $context->platform;
        if ($platform === null || $context->portableRoot === null || $context->managedSymlink === null) {
            return $this->fail('update.portable_context_invalid');
        }

        $version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        $artifactName = "stud-portable-{$version}-{$platform}.tar.gz";
        $portableAsset = $this->findAsset($release, $artifactName);
        $checksumsAsset = $this->findAsset($release, 'checksums.txt');
        if ($portableAsset === null || $checksumsAsset === null) {
            return $this->fail('update.portable_asset_missing');
        }

        return $this->installPortableAsset($context, [
            'portable' => $portableAsset,
            'checksums' => $checksumsAsset,
        ], $artifactName, $quiet);
    }

    protected function fail(string $translationKey): int
    {
        $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key($translationKey));

        return 1;
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>|null
     */
    protected function findAsset(array $release, string $name): ?array
    {
        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? null) === $name) {
                return is_array($asset) ? $asset : null;
            }
        }

        return null;
    }

    /**
     * @param array{portable: array<string, mixed>, checksums: array<string, mixed>} $assets
     */
    protected function installPortableAsset(
        UpdateInstallContext $context,
        array $assets,
        string $artifactName,
        bool $quiet,
    ): int {
        $workspace = $this->createWorkspace();

        try {
            $archivePath = $workspace . '/' . $artifactName;
            $checksumsPath = $workspace . '/checksums.txt';
            $this->downloadAsset($assets['portable'], $archivePath);
            $this->downloadAsset($assets['checksums'], $checksumsPath);
            if (! $this->verifyChecksum($checksumsPath, $archivePath, $artifactName)) {
                return 1;
            }

            $extractedRoot = $this->extractArchive($workspace, $artifactName);
            if ($extractedRoot === null || ! $this->smokeCheck($extractedRoot . '/stud')) {
                return 1;
            }

            return $this->activateBundle($context, $extractedRoot, $artifactName, $quiet);
        } catch (\Throwable $e) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('update.portable_update_failed', ['error' => $e->getMessage()]));

            return 1;
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    protected function createWorkspace(): string
    {
        $workspace = sys_get_temp_dir() . '/stud-portable-update-' . bin2hex(random_bytes(6));
        if (! mkdir($workspace, 0777, true) && ! is_dir($workspace)) {
            // @codeCoverageIgnoreStart
            throw new \RuntimeException("Failed to create update workspace: {$workspace}");
            // @codeCoverageIgnoreEnd
        }

        return $workspace;
    }

    /**
     * @param array<string, mixed> $asset
     */
    protected function downloadAsset(array $asset, string $targetPath): void
    {
        $assetId = $asset['id'] ?? null;
        if (! $assetId) {
            throw new \RuntimeException('Release asset ID is missing.');
        }

        $apiUrl = sprintf(
            'https://api.github.com/repos/%s/%s/releases/assets/%s',
            $this->repository->owner,
            $this->repository->name,
            $assetId
        );
        $response = $this->client()->request('GET', $apiUrl);
        file_put_contents($targetPath, $response->getContent());
    }

    protected function client(): HttpClientInterface
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }

        $headers = [
            'User-Agent' => 'stud-cli',
            'Accept' => 'application/octet-stream',
        ];
        if ($this->repository->token !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->repository->token;
        }

        return HttpClient::create(['headers' => $headers]);
    }

    protected function verifyChecksum(string $checksumsPath, string $archivePath, string $artifactName): bool
    {
        $expectedHash = $this->expectedChecksum($checksumsPath, $artifactName);
        $actualHash = hash_file('sha256', $archivePath);
        if ($expectedHash === null || $actualHash === false || ! hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('update.portable_checksum_failed'));

            return false;
        }

        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('update.success_hash_verified'));

        return true;
    }

    protected function expectedChecksum(string $checksumsPath, string $artifactName): ?string
    {
        $lines = file($checksumsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        foreach ($lines as $line) {
            if (str_ends_with($line, '  ' . $artifactName)) {
                return trim(substr($line, 0, 64));
            }
        }

        return null;
    }

    protected function extractArchive(string $workspace, string $artifactName): ?string
    {
        $process = new Process(['tar', '-xzf', $workspace . '/' . $artifactName, '-C', $workspace]);
        $process->run();
        if (! $process->isSuccessful()) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('update.portable_extract_failed'));

            return null;
        }

        $artifactRoot = $workspace . '/' . basename($artifactName, '.tar.gz');

        return is_file($artifactRoot . '/stud') ? $artifactRoot : null;
    }

    protected function smokeCheck(string $launcherPath): bool
    {
        $process = new Process([$launcherPath, '--version']);
        $process->run();
        if ($process->isSuccessful()) {
            return true;
        }

        $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('update.portable_smoke_failed'));

        return false;
    }

    protected function activateBundle(
        UpdateInstallContext $context,
        string $extractedRoot,
        string $artifactName,
        bool $quiet,
    ): int {
        $version = $this->versionFromArtifact($artifactName, (string) $context->platform);
        $versionRoot = $context->portableRoot . '/' . $context->platform . '/' . $version;
        $previousTarget = is_link((string) $context->managedSymlink) ? readlink((string) $context->managedSymlink) : false;
        if ($previousTarget === false || ! $this->isManagedTarget((string) $previousTarget, (string) $context->portableRoot)) {
            return $this->fail('update.portable_unmanaged_symlink');
        }

        $this->removeDirectory($versionRoot);
        if (! is_dir(dirname($versionRoot))) {
            mkdir(dirname($versionRoot), 0777, true);
        }
        rename($extractedRoot, $versionRoot);

        if (! $this->switchSymlink((string) $context->managedSymlink, $versionRoot . '/stud', (string) $previousTarget)) {
            // @codeCoverageIgnoreStart
            return 1;
            // @codeCoverageIgnoreEnd
        }

        $this->logger->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('update.portable_success', ['version' => $version]));
        $this->cleanupOldVersions($context, $version, $quiet);

        return 0;
    }

    protected function versionFromArtifact(string $artifactName, string $platform): string
    {
        $version = substr($artifactName, strlen('stud-portable-'));

        return substr($version, 0, -strlen("-{$platform}.tar.gz"));
    }

    protected function isManagedTarget(string $target, string $portableRoot): bool
    {
        return str_starts_with($target, rtrim($portableRoot, '/') . '/');
    }

    protected function switchSymlink(string $symlinkPath, string $newTarget, string $previousTarget): bool
    {
        $temporaryLink = $symlinkPath . '.new';
        @unlink($temporaryLink);
        if (! symlink($newTarget, $temporaryLink)) {
            // @codeCoverageIgnoreStart
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('update.portable_symlink_failed'));

            return false;
            // @codeCoverageIgnoreEnd
        }

        if (@rename($temporaryLink, $symlinkPath)) {
            return true;
        }

        // @codeCoverageIgnoreStart
        @unlink($temporaryLink);
        @unlink($symlinkPath);
        @symlink($previousTarget, $symlinkPath);
        $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('update.portable_symlink_failed'));

        return false;
        // @codeCoverageIgnoreEnd
    }

    protected function cleanupOldVersions(UpdateInstallContext $context, string $currentVersion, bool $quiet): void
    {
        if (! $this->shouldCleanupOldVersions($quiet)) {
            return;
        }

        $platformRoot = $context->portableRoot . '/' . $context->platform;
        foreach ($this->oldVersionDirectories($platformRoot, $currentVersion) as $path) {
            $this->removeDirectory($path);
        }
    }

    protected function shouldCleanupOldVersions(bool $quiet): bool
    {
        if ($quiet) {
            return false;
        }

        return $this->logger->confirm(MessageRef::key('update.portable_cleanup_prompt'), false);
    }

    /**
     * @return list<string>
     */
    protected function oldVersionDirectories(string $platformRoot, string $currentVersion): array
    {
        $items = scandir($platformRoot);
        if ($items === false) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }

        $versions = [];
        foreach ($items as $item) {
            $path = $platformRoot . '/' . $item;
            if ($item === '.' || $item === '..' || $item === $currentVersion || ! is_dir($path) || is_link($path)) {
                continue;
            }

            $versions[] = $path;
        }

        return $versions;
    }

    protected function removeDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $items = scandir($path);
        if ($items === false) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removeDirectory($path . '/' . $item);
        }

        @rmdir($path);
    }
}
