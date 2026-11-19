<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ManagedInstallPaths;
use App\Service\OldVersionCleaner;
use PHPUnit\Framework\TestCase;

class OldVersionCleanerTest extends TestCase
{
    private string $workspace;
    private string $home;
    private ManagedInstallPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/old-version-cleaner-test-' . bin2hex(random_bytes(4));
        $this->home = $this->workspace . '/home';
        mkdir($this->home . '/.local/bin', 0777, true);
        $this->paths = ManagedInstallPaths::fromHome($this->home);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    public function testMarkPendingWritesTheConsentMarker(): void
    {
        self::assertTrue((new OldVersionCleaner($this->paths))->markPending());

        self::assertFileExists($this->paths->cleanupMarker());
    }

    public function testMarkPendingFailsWhenTheRootCannotBeCreated(): void
    {
        mkdir($this->home . '/.local/share', 0777, true);
        file_put_contents($this->paths->root(), 'not a directory');

        self::assertFalse((new OldVersionCleaner($this->paths))->markPending());
    }

    public function testRunPendingDoesNothingWithoutAMarker(): void
    {
        $this->installPharLayout('1.0.0', '2.0.0');

        self::assertSame([], (new OldVersionCleaner($this->paths))->runPending($this->paths->pharFile('2.0.0')));

        self::assertFileExists($this->paths->pharFile('1.0.0'));
    }

    public function testRunPendingKeepsEverythingWhenRunningFromAnotherVersion(): void
    {
        $this->installPharLayout('1.0.0', '2.0.0');
        (new OldVersionCleaner($this->paths))->markPending();

        $failed = (new OldVersionCleaner($this->paths))->runPending($this->paths->pharFile('1.0.0'));

        self::assertSame([], $failed);
        self::assertFileExists($this->paths->pharFile('1.0.0'));
        self::assertFileExists($this->paths->cleanupMarker());
    }

    public function testRunPendingKeepsEverythingWhenTheSymlinkIsNotManaged(): void
    {
        $foreign = $this->workspace . '/foreign.phar';
        file_put_contents($foreign, 'phar');
        symlink($foreign, $this->paths->managedSymlink());
        mkdir($this->paths->pharDirectory(), 0777, true);
        file_put_contents($this->paths->pharFile('1.0.0'), 'old');
        (new OldVersionCleaner($this->paths))->markPending();

        self::assertSame([], (new OldVersionCleaner($this->paths))->runPending($foreign));

        self::assertFileExists($this->paths->pharFile('1.0.0'));
        self::assertFileExists($this->paths->cleanupMarker());
    }

    public function testRunPendingRemovesOlderPharFilesAndClearsTheMarker(): void
    {
        $this->installPharLayout('1.0.0', '2.0.0');
        (new OldVersionCleaner($this->paths))->markPending();

        $failed = (new OldVersionCleaner($this->paths))->runPending($this->paths->pharFile('2.0.0'));

        self::assertSame([], $failed);
        self::assertFileDoesNotExist($this->paths->pharFile('1.0.0'));
        self::assertFileExists($this->paths->pharFile('2.0.0'));
        self::assertFileDoesNotExist($this->paths->cleanupMarker());
    }

    public function testRunPendingRemovesOlderPortableVersionsAndKeepsHiddenEntriesAndSymlinks(): void
    {
        $launcher = $this->paths->root() . '/linux-amd64/2.0.0/stud';
        mkdir(dirname($launcher) . '/app', 0777, true);
        file_put_contents($launcher, 'launcher');
        symlink($launcher, $this->paths->managedSymlink());
        $olderVersion = $this->paths->root() . '/linux-amd64/1.0.0';
        mkdir($olderVersion . '/app', 0777, true);
        file_put_contents($olderVersion . '/stud', 'old');
        file_put_contents($this->paths->root() . '/linux-amd64/.hidden', 'keep');
        symlink($launcher, $this->paths->root() . '/linux-amd64/link-to-active');
        (new OldVersionCleaner($this->paths))->markPending();

        $failed = (new OldVersionCleaner($this->paths))->runPending(dirname($launcher) . '/app/stud.phar');

        self::assertSame([], $failed);
        self::assertDirectoryDoesNotExist($olderVersion);
        self::assertDirectoryExists(dirname($launcher));
        self::assertFileExists($this->paths->root() . '/linux-amd64/.hidden');
        self::assertTrue(is_link($this->paths->root() . '/linux-amd64/link-to-active'));
        self::assertFileDoesNotExist($this->paths->cleanupMarker());
    }

    public function testRunPendingReportsFailedRemovalsAndKeepsTheMarker(): void
    {
        $this->installPharLayout('1.0.0', '2.0.0');
        (new OldVersionCleaner($this->paths))->markPending();
        $blocked = $this->paths->pharFile('1.0.0');

        $cleaner = new class ($this->paths, $blocked) extends OldVersionCleaner {
            public function __construct(ManagedInstallPaths $paths, private readonly string $blocked)
            {
                parent::__construct($paths);
            }

            public function removeDirectory(string $path): bool
            {
                return $path === $this->blocked ? false : parent::removeDirectory($path);
            }
        };

        $failed = $cleaner->runPending($this->paths->pharFile('2.0.0'));

        self::assertSame([$blocked], $failed);
        self::assertFileExists($this->paths->cleanupMarker());
    }

    public function testRunPendingReportsAnUnreadableVersionAndKeepsTheMarker(): void
    {
        $this->skipWhenRoot();
        $launcher = $this->paths->root() . '/linux-amd64/2.0.0/stud';
        mkdir(dirname($launcher), 0777, true);
        file_put_contents($launcher, 'launcher');
        symlink($launcher, $this->paths->managedSymlink());
        $olderVersion = $this->paths->root() . '/linux-amd64/1.0.0';
        mkdir($olderVersion, 0777, true);
        file_put_contents($olderVersion . '/stud', 'old');
        (new OldVersionCleaner($this->paths))->markPending();

        try {
            chmod($olderVersion, 0000);
            $failed = (new OldVersionCleaner($this->paths))->runPending($launcher);
        } finally {
            chmod($olderVersion, 0755);
        }

        self::assertSame([$olderVersion], $failed);
        self::assertFileExists($this->paths->cleanupMarker());
    }

    public function testRunPendingReportsTheVersionsFolderWhenItCannotBeListed(): void
    {
        $this->skipWhenRoot();
        $launcher = $this->paths->root() . '/linux-amd64/2.0.0/stud';
        mkdir(dirname($launcher), 0777, true);
        file_put_contents($launcher, 'launcher');
        symlink($launcher, $this->paths->managedSymlink());
        (new OldVersionCleaner($this->paths))->markPending();
        $versionsFolder = $this->paths->root() . '/linux-amd64';

        try {
            chmod($versionsFolder, 0311);
            $failed = (new OldVersionCleaner($this->paths))->runPending($launcher);
        } finally {
            chmod($versionsFolder, 0755);
        }

        self::assertSame([$versionsFolder], $failed);
        self::assertFileExists($this->paths->cleanupMarker());
    }

    public function testRunPendingUnlinksNestedSymlinksWithoutFollowingThem(): void
    {
        $launcher = $this->paths->root() . '/linux-amd64/2.0.0/stud';
        mkdir(dirname($launcher), 0777, true);
        file_put_contents($launcher, 'launcher');
        symlink($launcher, $this->paths->managedSymlink());
        $outside = $this->workspace . '/outside';
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/keep', 'keep');
        $olderVersion = $this->paths->root() . '/linux-amd64/1.0.0';
        mkdir($olderVersion, 0777, true);
        symlink($outside, $olderVersion . '/escape');
        (new OldVersionCleaner($this->paths))->markPending();

        $failed = (new OldVersionCleaner($this->paths))->runPending($launcher);

        self::assertSame([], $failed);
        self::assertDirectoryDoesNotExist($olderVersion);
        self::assertFileExists($outside . '/keep');
    }

    public function testRemoveDirectoryReturnsTrueForMissingPath(): void
    {
        self::assertTrue((new OldVersionCleaner($this->paths))->removeDirectory($this->workspace . '/missing'));
    }

    public function testRemoveDirectoryRemovesNestedContent(): void
    {
        $directory = $this->workspace . '/tree';
        mkdir($directory . '/nested', 0777, true);
        file_put_contents($directory . '/nested/file', 'x');

        self::assertTrue((new OldVersionCleaner($this->paths))->removeDirectory($directory));
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testRemoveDirectoryPropagatesChildFailure(): void
    {
        $directory = $this->workspace . '/tree';
        mkdir($directory . '/child', 0777, true);

        $cleaner = new class ($this->paths) extends OldVersionCleaner {
            public function removeDirectory(string $path): bool
            {
                return str_ends_with($path, '/child') ? false : parent::removeDirectory($path);
            }
        };

        self::assertFalse($cleaner->removeDirectory($directory));
    }

    private function installPharLayout(string $olderVersion, string $activeVersion): void
    {
        mkdir($this->paths->pharDirectory(), 0777, true);
        file_put_contents($this->paths->pharFile($olderVersion), 'old');
        file_put_contents($this->paths->pharFile($activeVersion), 'new');
        symlink($this->paths->pharFile($activeVersion), $this->paths->managedSymlink());
    }

    private function skipWhenRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root can read directories that permissions block.');
        }
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
