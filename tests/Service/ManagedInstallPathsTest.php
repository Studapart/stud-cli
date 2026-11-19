<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ManagedInstallPaths;
use PHPUnit\Framework\TestCase;

class ManagedInstallPathsTest extends TestCase
{
    private string $workspace;
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/managed-install-paths-test-' . bin2hex(random_bytes(4));
        $this->home = $this->workspace . '/home';
        mkdir($this->home . '/.local/bin', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    public function testDerivesEveryManagedPathFromHome(): void
    {
        $paths = ManagedInstallPaths::fromHome('/home/user/');

        self::assertSame('/home/user/.local/share/stud-portable', $paths->root());
        self::assertSame('/home/user/.local/bin/stud', $paths->managedSymlink());
        self::assertSame('/home/user/.local/share/stud-portable/phar', $paths->pharDirectory());
        self::assertSame('/home/user/.local/share/stud-portable/phar/1.2.3-stud.phar', $paths->pharFile('1.2.3'));
        self::assertSame('/home/user/.local/share/stud-portable/.cleanup-pending', $paths->cleanupMarker());
    }

    public function testFromEnvironmentIsUnconfiguredWithoutHome(): void
    {
        $previousServerHome = $_SERVER['HOME'] ?? null;
        $previousEnvHome = getenv('HOME');
        unset($_SERVER['HOME']);
        putenv('HOME');

        try {
            $paths = ManagedInstallPaths::fromEnvironment();
        } finally {
            if ($previousServerHome !== null) {
                $_SERVER['HOME'] = $previousServerHome;
            }
            if (is_string($previousEnvHome)) {
                putenv('HOME=' . $previousEnvHome);
            }
        }

        self::assertFalse($paths->isConfigured());
        $this->expectException(\LogicException::class);
        $paths->root();
    }

    public function testActiveEntryIsThePharFileBehindTheManagedSymlink(): void
    {
        $paths = ManagedInstallPaths::fromHome($this->home);
        $pharFile = $paths->pharFile('2.0.0');
        mkdir(dirname($pharFile), 0777, true);
        file_put_contents($pharFile, 'phar');
        symlink($pharFile, $paths->managedSymlink());

        self::assertSame(realpath($pharFile), $paths->activeEntry());
    }

    public function testActiveEntryIsTheVersionDirectoryOfAPortableBundle(): void
    {
        $paths = ManagedInstallPaths::fromHome($this->home);
        $launcher = $paths->root() . '/linux-amd64/2.0.0/stud';
        mkdir(dirname($launcher), 0777, true);
        file_put_contents($launcher, 'launcher');
        symlink($launcher, $paths->managedSymlink());

        self::assertSame(realpath($paths->root()) . '/linux-amd64/2.0.0', $paths->activeEntry());
    }

    public function testActiveEntryIsNullWhenTheSymlinkIsMissing(): void
    {
        self::assertNull(ManagedInstallPaths::fromHome($this->home)->activeEntry());
    }

    public function testActiveEntryIsNullWhenTheSymlinkPointsOutsideTheRoot(): void
    {
        $paths = ManagedInstallPaths::fromHome($this->home);
        mkdir($paths->root() . '/phar', 0777, true);
        $foreign = $this->workspace . '/foreign.phar';
        file_put_contents($foreign, 'phar');
        symlink($foreign, $paths->managedSymlink());

        self::assertNull($paths->activeEntry());
    }

    public function testActiveEntryIsNullWhenTheTargetIsDirectlyUnderRoot(): void
    {
        $paths = ManagedInstallPaths::fromHome($this->home);
        mkdir($paths->root(), 0777, true);
        $flat = $paths->root() . '/stud';
        file_put_contents($flat, 'launcher');
        symlink($flat, $paths->managedSymlink());

        self::assertNull($paths->activeEntry());
    }

    public function testManagedLinkAcceptsRelativeAndDanglingTargetsInsideRoot(): void
    {
        $paths = ManagedInstallPaths::fromHome($this->home);
        mkdir($paths->pharDirectory(), 0777, true);
        symlink('../share/stud-portable/phar/9.9.9-stud.phar', $paths->managedSymlink());

        self::assertTrue($paths->isManagedLink($paths->managedSymlink()));
    }

    public function testManagedLinkRejectsTargetsOutsideRoot(): void
    {
        $paths = ManagedInstallPaths::fromHome($this->home);
        $foreign = $this->workspace . '/foreign.phar';
        file_put_contents($foreign, 'phar');
        symlink($foreign, $paths->managedSymlink());

        self::assertFalse($paths->isManagedLink($paths->managedSymlink()));
    }

    public function testManagedLinkIsFalseForPlainFile(): void
    {
        $paths = ManagedInstallPaths::fromHome($this->home);
        file_put_contents($paths->managedSymlink(), 'phar');

        self::assertFalse($paths->isManagedLink($paths->managedSymlink()));
    }

    public function testIsWithinComparesDirectoryPrefixes(): void
    {
        $paths = ManagedInstallPaths::fromHome($this->home);

        self::assertTrue($paths->isWithin($this->home, $this->home . '/anything'));
        self::assertFalse($paths->isWithin($this->home . '/phar', $this->home . '/phar-other/stud.phar'));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeTree($path . '/' . $item);
            }
        }
        rmdir($path);
    }
}
