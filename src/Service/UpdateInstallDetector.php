<?php

declare(strict_types=1);

namespace App\Service;

class UpdateInstallDetector
{
    private const MANIFEST = '.stud-portable.json';

    /**
     * Detects whether the current update target is a PHAR or portable bundle.
     */
    public function detect(string $binaryPath, string $currentVersion): UpdateInstallContext
    {
        $bundleRoot = $this->resolvePortableBundleRoot($binaryPath);
        if ($bundleRoot === null) {
            return new UpdateInstallContext(UpdateInstallContext::MODE_PHAR, $currentVersion, $binaryPath);
        }

        $manifest = $this->readManifest($bundleRoot);
        if ($manifest !== null) {
            $platform = $this->stringValue($manifest['platform'] ?? null);

            return $this->createPortableContext($currentVersion, $binaryPath, $bundleRoot, [
                'platform' => $platform,
                'legacyPortableLayout' => false,
            ]);
        }

        if ($this->looksLikeLegacyPortableBundle($bundleRoot)) {
            return $this->createPortableContext($currentVersion, $binaryPath, $bundleRoot, [
                'platform' => basename($bundleRoot),
                'legacyPortableLayout' => true,
            ]);
        }

        return new UpdateInstallContext(UpdateInstallContext::MODE_PHAR, $currentVersion, $binaryPath);
    }

    protected function resolvePortableBundleRoot(string $binaryPath): ?string
    {
        $normalizedPath = str_starts_with($binaryPath, 'phar://')
            ? substr($binaryPath, 7)
            : $binaryPath;

        if (basename($normalizedPath) !== 'stud.phar') {
            return null;
        }

        $appDir = dirname($normalizedPath);
        if (basename($appDir) !== 'app') {
            return null;
        }

        return dirname($appDir);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readManifest(string $bundleRoot): ?array
    {
        $manifestPath = $bundleRoot . '/' . self::MANIFEST;
        if (! is_file($manifestPath)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($payload) || ($payload['installMode'] ?? null) !== UpdateInstallContext::MODE_PORTABLE) {
            return null;
        }

        return $payload;
    }

    protected function looksLikeLegacyPortableBundle(string $bundleRoot): bool
    {
        return is_file($bundleRoot . '/stud')
            && is_file($bundleRoot . '/runtime/php')
            && str_contains($bundleRoot, '/stud-portable/');
    }

    /**
     * @param array{platform: ?string, legacyPortableLayout: bool} $metadata
     */
    protected function createPortableContext(
        string $currentVersion,
        string $binaryPath,
        string $bundleRoot,
        array $metadata,
    ): UpdateInstallContext {
        $legacyPortableLayout = $metadata['legacyPortableLayout'] === true;
        $portableRoot = $legacyPortableLayout ? dirname($bundleRoot) : dirname(dirname($bundleRoot));
        $managedSymlink = $this->managedSymlinkPath();

        return new UpdateInstallContext(
            UpdateInstallContext::MODE_PORTABLE,
            $currentVersion,
            $binaryPath,
            $this->stringValue($metadata['platform'] ?? null),
            $bundleRoot,
            $portableRoot,
            $bundleRoot . '/stud',
            $managedSymlink,
            $legacyPortableLayout
        );
    }

    protected function managedSymlinkPath(): ?string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: null;
        if (! is_string($home)) {
            return null;
        }

        return rtrim($home, '/') . '/.local/bin/stud';
    }

    protected function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
