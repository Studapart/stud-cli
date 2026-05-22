<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PrototypePortableScriptTest extends TestCase
{
    private string $workspace;
    private string $outputDir;
    private string $runtimePath;
    private string $pharPath;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 2);
        $this->workspace = $root . '/.cursor/tmp/prototype-portable-test-' . bin2hex(random_bytes(4));
        $this->outputDir = '.cursor/tmp/' . basename($this->workspace) . '/artifact';
        $this->runtimePath = $this->workspace . '/runtime/php';
        $this->pharPath = $this->workspace . '/stud-test.phar';

        mkdir($this->workspace . '/runtime', 0777, true);
        file_put_contents($this->pharPath, 'phar payload');
        file_put_contents($this->runtimePath, <<<'SH'
#!/usr/bin/env sh
printf 'runtime=%s\nphar=%s\nargs=%s\n' "$0" "$1" "${2:-}"
SH);
        chmod($this->runtimePath, 0755);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    public function testCreatesLauncherUsingBundledRuntime(): void
    {
        $process = $this->runPrototypeCommand([
            '--platform',
            'linux-amd64',
            '--phar',
            $this->pharPath,
            '--runtime',
            $this->runtimePath,
            '--output',
            $this->outputDir,
        ]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $artifactRoot = dirname(__DIR__, 2) . '/' . $this->outputDir;
        self::assertFileExists($artifactRoot . '/stud');
        self::assertFileExists($artifactRoot . '/runtime/php');
        self::assertFileExists($artifactRoot . '/app/stud.phar');
        self::assertFileExists($artifactRoot . '/README.md');

        $portableProcess = new Process([$artifactRoot . '/stud', '--version']);
        $portableProcess->mustRun();

        self::assertStringContainsString('runtime=' . $artifactRoot . '/runtime/php', $portableProcess->getOutput());
        self::assertStringContainsString('phar=' . $artifactRoot . '/app/stud.phar', $portableProcess->getOutput());
        self::assertStringContainsString('args=--version', $portableProcess->getOutput());
    }

    public function testRejectsUnsupportedPlatform(): void
    {
        $process = $this->runPrototypeCommand([
            '--platform',
            'darwin-arm64',
            '--phar',
            $this->pharPath,
            '--runtime',
            $this->runtimePath,
            '--output',
            $this->outputDir,
        ]);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Only linux-amd64 is supported', $process->getErrorOutput());
    }

    /**
     * @param list<string> $arguments
     */
    protected function runPrototypeCommand(array $arguments): Process
    {
        $root = dirname(__DIR__, 2);
        $process = new Process(array_merge([$root . '/scripts/prototype-portable'], $arguments), $root);
        $process->run();

        return $process;
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
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
