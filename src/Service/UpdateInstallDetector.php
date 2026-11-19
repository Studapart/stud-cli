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
            return $this->createPharContext($currentVersion, $binaryPath);
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

        return $this->createPharContext($currentVersion, $binaryPath);
    }

    /**
     * A PHAR is managed when it runs from the managed PHAR directory or is the file ~/.local/bin/stud resolves to.
     */
    protected function createPharContext(string $currentVersion, string $binaryPath): UpdateInstallContext
    {
        $mode = $this->isManagedPhar($binaryPath) ? UpdateInstallContext::MODE_PHAR : UpdateInstallContext::MODE_PHAR_IN_PLACE;

        return new UpdateInstallContext($mode, $currentVersion, $binaryPath);
    }

    protected function isManagedPhar(string $binaryPath): bool
    {
        $paths = ManagedInstallPaths::fromEnvironment();
        if (! $paths->isConfigured()) {
            return false;
        }

        $resolvedBinary = realpath($binaryPath);

        return $paths->isWithin($paths->pharDirectory(), $binaryPath)
            || ($resolvedBinary !== false && $resolvedBinary === realpath($paths->managedSymlink()));
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
        $paths = ManagedInstallPaths::fromEnvironment();

        return new UpdateInstallContext(
            UpdateInstallContext::MODE_PORTABLE,
            $currentVersion,
            $binaryPath,
            $this->stringValue($metadata['platform'] ?? null),
            $bundleRoot,
            $portableRoot,
            $bundleRoot . '/stud',
            $paths->isConfigured() ? $paths->managedSymlink() : null,
            $legacyPortableLayout
        );
    }

    protected function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
