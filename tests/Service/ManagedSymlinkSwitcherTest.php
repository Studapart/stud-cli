<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ManagedSymlinkSwitcher;
use PHPUnit\Framework\TestCase;

class ManagedSymlinkSwitcherTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/managed-symlink-switcher-test-' . bin2hex(random_bytes(4));
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        parent::tearDown();
    }

    public function testReplacesAnExistingSymlink(): void
    {
        file_put_contents($this->workspace . '/old', 'old');
        file_put_contents($this->workspace . '/new', 'new');
        symlink($this->workspace . '/old', $this->workspace . '/stud');

        self::assertTrue((new ManagedSymlinkSwitcher())->switch($this->workspace . '/stud', $this->workspace . '/new'));

        self::assertSame($this->workspace . '/new', readlink($this->workspace . '/stud'));
        self::assertFileDoesNotExist($this->workspace . '/stud.new');
    }

    public function testReplacesAPlainFileWithASymlink(): void
    {
        file_put_contents($this->workspace . '/stud', 'plain');
        file_put_contents($this->workspace . '/new', 'new');

        self::assertTrue((new ManagedSymlinkSwitcher())->switch($this->workspace . '/stud', $this->workspace . '/new'));

        self::assertTrue(is_link($this->workspace . '/stud'));
        self::assertSame('new', file_get_contents($this->workspace . '/stud'));
    }

    public function testLeavesThePathUntouchedWhenTheTemporaryLinkCannotBeCreated(): void
    {
        file_put_contents($this->workspace . '/stud', 'plain');

        self::assertFalse((new ManagedSymlinkSwitcher())->switch($this->workspace . '/missing/stud', $this->workspace . '/new'));

        self::assertSame('plain', file_get_contents($this->workspace . '/stud'));
    }

    public function testRemovesTheTemporaryLinkWhenTheRenameFails(): void
    {
        $occupied = $this->workspace . '/stud';
        mkdir($occupied);
        file_put_contents($occupied . '/keep', 'keep');

        self::assertFalse((new ManagedSymlinkSwitcher())->switch($occupied, $this->workspace . '/new'));

        self::assertFileDoesNotExist($occupied . '.new');
        self::assertFileExists($occupied . '/keep');
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
