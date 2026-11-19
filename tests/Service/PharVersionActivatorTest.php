<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\WorkflowRecorder;
use App\Service\ManagedInstallPaths;
use App\Service\ManagedSymlinkSwitcher;
use App\Service\PharVersionActivator;
use App\Service\UpdateInstallContext;
use PHPUnit\Framework\TestCase;

class PharVersionActivatorTest extends TestCase
{
    private string $workspace;
    private string $downloaded;
    private ManagedInstallPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/phar-version-activator-test-' . bin2hex(random_bytes(4));
        mkdir($this->workspace . '/home/.local/bin', 0777, true);
        $this->paths = ManagedInstallPaths::fromHome($this->workspace . '/home');
        $this->downloaded = $this->workspace . '/stud.phar.download';
        file_put_contents($this->downloaded, 'new phar');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    public function testVersionedInstallSwitchesTheSymlinkAndKeepsTheRunningCopy(): void
    {
        mkdir($this->paths->pharDirectory(), 0777, true);
        file_put_contents($this->paths->pharFile('1.0.0'), 'old phar');
        symlink($this->paths->pharFile('1.0.0'), $this->paths->managedSymlink());
        $recorder = new WorkflowRecorder();

        $result = $this->activate('1.0.0', '1.1.0', $recorder);

        self::assertSame(0, $result);
        self::assertSame($this->paths->pharFile('1.1.0'), readlink($this->paths->managedSymlink()));
        self::assertSame('new phar', file_get_contents($this->paths->pharFile('1.1.0')));
        self::assertSame('old phar', file_get_contents($this->paths->pharFile('1.0.0')));
        self::assertSame(['success'], $this->types($recorder));
    }

    public function testPlainFileAtManagedPathIsKeptAsTheCurrentVersionAndReplacedBySymlink(): void
    {
        file_put_contents($this->paths->managedSymlink(), 'legacy phar');

        $result = $this->activate('1.0.0', '1.1.0', new WorkflowRecorder());

        self::assertSame(0, $result);
        self::assertTrue(is_link($this->paths->managedSymlink()));
        self::assertSame('legacy phar', file_get_contents($this->paths->pharFile('1.0.0')));
        self::assertSame($this->paths->pharFile('1.1.0'), readlink($this->paths->managedSymlink()));
    }

    public function testExistingCurrentVersionCopyIsNotOverwrittenWhenConvertingAPlainFile(): void
    {
        mkdir($this->paths->pharDirectory(), 0777, true);
        file_put_contents($this->paths->pharFile('1.0.0'), 'already kept');
        file_put_contents($this->paths->managedSymlink(), 'legacy phar');

        $this->activate('1.0.0', '1.1.0', new WorkflowRecorder());

        self::assertSame('already kept', file_get_contents($this->paths->pharFile('1.0.0')));
    }

    public function testPlainFileThatIsNotTheRunningCopyIsRefusedWithoutChanges(): void
    {
        file_put_contents($this->paths->managedSymlink(), 'wrapper script');
        $running = $this->workspace . '/running/stud.phar';
        mkdir(dirname($running), 0777, true);
        file_put_contents($running, 'running phar');
        $recorder = new WorkflowRecorder();

        $result = (new PharVersionActivator($this->paths, new ManagedSymlinkSwitcher()))->activate(
            new UpdateInstallContext(UpdateInstallContext::MODE_PHAR, '1.0.0', $running),
            $this->downloaded,
            '1.1.0',
            $recorder,
        );

        self::assertSame(1, $result);
        self::assertSame('wrapper script', file_get_contents($this->paths->managedSymlink()));
        self::assertFileDoesNotExist($this->paths->pharFile('1.0.0'));
        self::assertSame(['error'], $this->types($recorder));
    }

    public function testPlainFileIsNotConvertedWhenTheCurrentVersionIsNotAFileNameToken(): void
    {
        file_put_contents($this->paths->managedSymlink(), 'legacy phar');

        $result = (new PharVersionActivator($this->paths, new ManagedSymlinkSwitcher()))->activate(
            new UpdateInstallContext(UpdateInstallContext::MODE_PHAR, '../1.0.0', $this->paths->managedSymlink()),
            $this->downloaded,
            '1.1.0',
            new WorkflowRecorder(),
        );

        self::assertSame(1, $result);
        self::assertSame('legacy phar', file_get_contents($this->paths->managedSymlink()));
    }

    public function testSymlinkToAForeignFileIsRefusedWithoutChanges(): void
    {
        $foreign = $this->workspace . '/foreign.phar';
        file_put_contents($foreign, 'foreign');
        symlink($foreign, $this->paths->managedSymlink());
        $recorder = new WorkflowRecorder();

        $result = $this->activate('1.0.0', '1.1.0', $recorder);

        self::assertSame(1, $result);
        self::assertSame($foreign, readlink($this->paths->managedSymlink()));
        self::assertFileDoesNotExist($this->paths->pharFile('1.1.0'));
        self::assertSame(['error'], $this->types($recorder));
    }

    public function testVersionThatIsNotAFileNameTokenIsRefusedWithoutChanges(): void
    {
        foreach (['../../escape', "1.1.0\n"] as $version) {
            $recorder = new WorkflowRecorder();

            $result = $this->activate('1.0.0', $version, $recorder);

            self::assertSame(1, $result);
            self::assertFileDoesNotExist($this->paths->pharFile($version));
            self::assertFalse(file_exists($this->paths->managedSymlink()));
            self::assertSame(['error'], $this->types($recorder));
        }
    }

    public function testMissingDownloadLeavesTheActiveVersionInPlace(): void
    {
        mkdir($this->paths->pharDirectory(), 0777, true);
        file_put_contents($this->paths->pharFile('1.0.0'), 'old phar');
        symlink($this->paths->pharFile('1.0.0'), $this->paths->managedSymlink());
        $recorder = new WorkflowRecorder();

        $result = (new PharVersionActivator($this->paths, new ManagedSymlinkSwitcher()))->activate(
            new UpdateInstallContext(UpdateInstallContext::MODE_PHAR, '1.0.0', $this->workspace . '/stud.phar'),
            $this->workspace . '/does-not-exist.phar',
            '1.1.0',
            $recorder,
        );

        self::assertSame(1, $result);
        self::assertSame($this->paths->pharFile('1.0.0'), readlink($this->paths->managedSymlink()));
        self::assertSame(['error'], $this->types($recorder));
    }

    public function testFailedSymlinkSwitchReportsAnErrorAndKeepsTheInstalledFile(): void
    {
        mkdir($this->paths->managedSymlink(), 0777, true);
        file_put_contents($this->paths->managedSymlink() . '/keep', 'keep');
        $recorder = new WorkflowRecorder();

        $result = $this->activate('1.0.0', '1.1.0', $recorder);

        self::assertSame(1, $result);
        self::assertFileExists($this->paths->managedSymlink() . '/keep');
        self::assertFileExists($this->paths->pharFile('1.1.0'));
        self::assertSame(['error'], $this->types($recorder));
    }

    public function testInstallCreatesTheManagedSymlinkWhenNoneExists(): void
    {
        $result = $this->activate('1.0.0', '1.1.0', new WorkflowRecorder());

        self::assertSame(0, $result);
        self::assertSame($this->paths->pharFile('1.1.0'), readlink($this->paths->managedSymlink()));
    }

    private function activate(string $currentVersion, string $version, WorkflowRecorder $recorder): int
    {
        return (new PharVersionActivator($this->paths, new ManagedSymlinkSwitcher()))->activate(
            new UpdateInstallContext(UpdateInstallContext::MODE_PHAR, $currentVersion, $this->paths->managedSymlink()),
            $this->downloaded,
            $version,
            $recorder,
        );
    }

    /**
     * @return list<string>
     */
    private function types(WorkflowRecorder $recorder): array
    {
        return array_values(array_map(static fn ($entry): string => $entry->type, $recorder->getEntries()));
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
