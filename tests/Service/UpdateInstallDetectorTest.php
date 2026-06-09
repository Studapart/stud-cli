<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\UpdateInstallContext;
use App\Service\UpdateInstallDetector;
use PHPUnit\Framework\TestCase;

class UpdateInstallDetectorTest extends TestCase
{
    private string $workspace;
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/update-detector-test-' . bin2hex(random_bytes(4));
        $this->home = $this->workspace . '/home';
        mkdir($this->home, 0777, true);
        $_SERVER['HOME'] = $this->home;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    public function testDetectsRegularPharInstall(): void
    {
        $context = (new UpdateInstallDetector())->detect($this->workspace . '/stud.phar', '1.0.0');

        self::assertSame(UpdateInstallContext::MODE_PHAR, $context->mode);
        self::assertFalse($context->legacyPortableLayout);
    }

    public function testDetectsVersionedPortableInstallFromManifest(): void
    {
        $bundleRoot = $this->createPortableBundle('linux-amd64/1.0.0', true);

        $context = (new UpdateInstallDetector())->detect($bundleRoot . '/app/stud.phar', '1.0.0');

        self::assertSame(UpdateInstallContext::MODE_PORTABLE, $context->mode);
        self::assertSame('linux-amd64', $context->platform);
        self::assertSame($bundleRoot, $context->bundleRoot);
        self::assertSame($this->workspace . '/stud-portable', $context->portableRoot);
        self::assertSame($this->home . '/.local/bin/stud', $context->managedSymlink);
        self::assertFalse($context->legacyPortableLayout);
    }

    public function testDetectsVersionedPortableInstallFromPharUri(): void
    {
        $bundleRoot = $this->createPortableBundle('linux-amd64/1.0.0', true);

        $context = (new UpdateInstallDetector())->detect('phar://' . $bundleRoot . '/app/stud.phar', '1.0.0');

        self::assertSame(UpdateInstallContext::MODE_PORTABLE, $context->mode);
        self::assertSame($bundleRoot, $context->bundleRoot);
    }

    public function testInvalidManifestFallsBackToPharWhenBundleIsNotLegacyPortable(): void
    {
        $bundleRoot = $this->workspace . '/stud-portable/linux-amd64/1.0.0';
        mkdir($bundleRoot . '/app', 0777, true);
        file_put_contents($bundleRoot . '/app/stud.phar', 'phar');
        file_put_contents($bundleRoot . '/.stud-portable.json', '{"installMode":"other"}');

        $context = (new UpdateInstallDetector())->detect($bundleRoot . '/app/stud.phar', '1.0.0');

        self::assertSame(UpdateInstallContext::MODE_PHAR, $context->mode);
    }

    public function testPortableContextAllowsMissingHomeForManagedSymlink(): void
    {
        $bundleRoot = $this->createPortableBundle('linux-amd64/1.0.0', true);
        $previousServerHome = $_SERVER['HOME'] ?? null;
        $previousEnvHome = getenv('HOME');
        unset($_SERVER['HOME']);
        putenv('HOME');

        try {
            $context = (new UpdateInstallDetector())->detect($bundleRoot . '/app/stud.phar', '1.0.0');
        } finally {
            if ($previousServerHome !== null) {
                $_SERVER['HOME'] = $previousServerHome;
            }
            if (is_string($previousEnvHome)) {
                putenv('HOME=' . $previousEnvHome);
            }
        }

        self::assertSame(UpdateInstallContext::MODE_PORTABLE, $context->mode);
        self::assertNull($context->managedSymlink);
    }

    public function testDetectsLegacyPortableInstallWithoutManifest(): void
    {
        $bundleRoot = $this->createPortableBundle('linux-amd64', false);

        $context = (new UpdateInstallDetector())->detect($bundleRoot . '/app/stud.phar', '1.0.0');

        self::assertSame(UpdateInstallContext::MODE_PORTABLE, $context->mode);
        self::assertSame('linux-amd64', $context->platform);
        self::assertSame($this->workspace . '/stud-portable', $context->portableRoot);
        self::assertTrue($context->legacyPortableLayout);
    }

    protected function createPortableBundle(string $path, bool $withManifest): string
    {
        $bundleRoot = $this->workspace . '/stud-portable/' . $path;
        mkdir($bundleRoot . '/app', 0777, true);
        mkdir($bundleRoot . '/runtime', 0777, true);
        file_put_contents($bundleRoot . '/app/stud.phar', 'phar');
        file_put_contents($bundleRoot . '/runtime/php', 'php');
        file_put_contents($bundleRoot . '/stud', 'launcher');

        if ($withManifest) {
            file_put_contents($bundleRoot . '/.stud-portable.json', json_encode([
                'installMode' => 'portable',
                'version' => '1.0.0',
                'platform' => 'linux-amd64',
            ], JSON_THROW_ON_ERROR));
        }

        return $bundleRoot;
    }

    protected function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath) && ! is_link($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
