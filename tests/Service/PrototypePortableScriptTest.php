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
case "${2:-}" in
    --version)
        printf 'runtime=%s\nphar=%s\nargs=%s\n' "$0" "$1" "$2"
        ;;
    help|config:validate)
        printf '{"success":true,"data":{"command":"%s"}}\n' "$2"
        ;;
    *)
        printf 'unexpected command: %s\n' "${2:-}" >&2
        exit 1
        ;;
esac
SH);
        chmod($this->runtimePath, 0755);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    public function testBuildPortableCreatesDefaultArtifactUsingBundledRuntime(): void
    {
        $process = $this->runScript('build-portable', [
            '--platform',
            'linux-amd64',
            '--phar',
            $this->pharPath,
            '--runtime',
            $this->runtimePath,
            '--output',
            dirname($this->outputDir),
        ]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $artifactPath = dirname($this->outputDir) . '/stud-portable-linux-amd64';
        $artifactRoot = dirname(__DIR__, 2) . '/' . $artifactPath;
        self::assertStringContainsString($artifactPath, $process->getOutput());
        $this->assertPortableArtifactRunsWithBundledRuntime($artifactRoot);
    }

    public function testPrototypeWrapperCreatesLauncherUsingBundledRuntime(): void
    {
        $process = $this->runScript('prototype-portable', [
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

        $this->assertPortableArtifactRunsWithBundledRuntime(dirname(__DIR__, 2) . '/' . $this->outputDir);
    }

    public function testSmokePortableRunsSafeCommands(): void
    {
        $buildProcess = $this->runScript('build-portable', [
            '--platform',
            'linux-amd64',
            '--phar',
            $this->pharPath,
            '--runtime',
            $this->runtimePath,
            '--output',
            dirname($this->outputDir),
        ]);
        self::assertSame(0, $buildProcess->getExitCode(), $buildProcess->getErrorOutput());

        $artifactRoot = dirname(__DIR__, 2) . '/' . dirname($this->outputDir) . '/stud-portable-linux-amd64';
        $smokeProcess = $this->runScript('smoke-portable', [
            '--binary',
            $artifactRoot . '/stud',
        ]);

        self::assertSame(0, $smokeProcess->getExitCode(), $smokeProcess->getErrorOutput());
        self::assertStringContainsString('Portable smoke checks passed', $smokeProcess->getOutput());
    }

    public function testSmokePortableRejectsInvalidAgentJson(): void
    {
        file_put_contents($this->runtimePath, <<<'SH'
#!/usr/bin/env sh
case "${2:-}" in
    --version)
        printf 'stud 0.0.0\n'
        ;;
    help|config:validate)
        printf 'not json\n'
        ;;
esac
SH);
        chmod($this->runtimePath, 0755);

        $buildProcess = $this->runScript('build-portable', [
            '--platform',
            'linux-amd64',
            '--phar',
            $this->pharPath,
            '--runtime',
            $this->runtimePath,
            '--output',
            dirname($this->outputDir),
        ]);
        self::assertSame(0, $buildProcess->getExitCode(), $buildProcess->getErrorOutput());

        $artifactRoot = dirname(__DIR__, 2) . '/' . dirname($this->outputDir) . '/stud-portable-linux-amd64';
        $smokeProcess = $this->runScript('smoke-portable', [
            '--binary',
            $artifactRoot . '/stud',
        ]);

        self::assertNotSame(0, $smokeProcess->getExitCode());
        self::assertStringContainsString('Smoke check failed: agent schema command JSON validation', $smokeProcess->getErrorOutput());
    }

    public function testRejectsUnsupportedPlatform(): void
    {
        $process = $this->runScript('build-portable', [
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

    public function testDownloadPortableRuntimeExtractsRuntimeExecutable(): void
    {
        $archivePath = $this->createRuntimeArchive();
        $outputDir = $this->workspace . '/downloaded-runtime';

        $process = $this->runScript('download-portable-runtime', [
            '--platform',
            'linux-amd64',
            '--url',
            'file://' . $archivePath,
            '--output',
            $outputDir,
        ]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString($outputDir . '/php', $process->getOutput());
        self::assertFileExists($outputDir . '/php');
        self::assertTrue(is_executable($outputDir . '/php'));
    }

    public function testCreateReleaseChecksumsWritesSortedChecksums(): void
    {
        $artifactDir = $this->workspace . '/release-artifacts';
        mkdir($artifactDir, 0777, true);
        file_put_contents($artifactDir . '/z-artifact.txt', 'z');
        file_put_contents($artifactDir . '/a-artifact.txt', 'a');

        $process = $this->runScript('create-release-checksums', [
            '--directory',
            $artifactDir,
            '--output',
            $artifactDir . '/checksums.txt',
        ]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $checksums = file($artifactDir . '/checksums.txt', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($checksums);
        self::assertCount(2, $checksums);
        self::assertStringEndsWith('  a-artifact.txt', $checksums[0]);
        self::assertStringEndsWith('  z-artifact.txt', $checksums[1]);
    }

    /**
     * @param list<string> $arguments
     */
    protected function runScript(string $script, array $arguments): Process
    {
        $root = dirname(__DIR__, 2);
        $process = new Process(array_merge([$root . '/scripts/' . $script], $arguments), $root);
        $process->run();

        return $process;
    }

    protected function assertPortableArtifactRunsWithBundledRuntime(string $artifactRoot): void
    {
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

    protected function createRuntimeArchive(): string
    {
        $archiveRoot = $this->workspace . '/runtime-archive';
        mkdir($archiveRoot . '/bin', 0777, true);
        file_put_contents($archiveRoot . '/bin/php', "#!/usr/bin/env sh\nexit 0\n");
        chmod($archiveRoot . '/bin/php', 0755);

        $archivePath = $this->workspace . '/runtime.tar.gz';
        $process = new Process(['tar', '-czf', $archivePath, '-C', $archiveRoot, '.']);
        $process->mustRun();

        return $archivePath;
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
