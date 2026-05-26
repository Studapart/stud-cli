<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SetupStudScriptTest extends TestCase
{
    private string $workspace;
    private string $fakeBin;
    private string $home;
    private string $curlLog;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 2);
        $this->workspace = $root . '/.cursor/tmp/setup-stud-test-' . bin2hex(random_bytes(4));
        $this->fakeBin = $this->workspace . '/bin';
        $this->home = $this->workspace . '/home';
        $this->curlLog = $this->workspace . '/curl.log';

        mkdir($this->fakeBin, 0777, true);
        mkdir($this->home, 0777, true);
        $this->writeDefaultFakes();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    public function testDefaultPharModeInstallsLatestPharAndChecksPhp(): void
    {
        $process = $this->runSetup(['--skip-init']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertFileExists($this->home . '/.local/bin/stud');
        self::assertStringContainsString('stud 9.8.7', $process->getOutput());
        self::assertStringContainsString('stud-9.8.7.phar', file_get_contents($this->curlLog) ?: '');
        self::assertStringContainsString('-r', file_get_contents($this->workspace . '/php.log') ?: '');
    }

    public function testPortableModeInstallsLinuxArtifactAndDoesNotRequireLocalPhp(): void
    {
        $artifactPath = $this->createPortableArchive('linux-amd64');
        $checksumPath = $this->createChecksums($artifactPath);
        $this->writeCurlFake($artifactPath, $checksumPath);
        $this->writePhpFakeThatFailsIfCalled();

        $process = $this->runSetup(['--portable', '--skip-init']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $bundleRoot = $this->home . '/.local/share/stud-portable/linux-amd64';
        self::assertFileExists($bundleRoot . '/stud');
        self::assertTrue(is_link($this->home . '/.local/bin/stud'));
        self::assertSame($bundleRoot . '/stud', readlink($this->home . '/.local/bin/stud'));
        $this->assertPortableOutputUsesBundle($process->getOutput(), $bundleRoot);
        self::assertFileDoesNotExist($this->workspace . '/php.log');

        $installedProcess = new Process(['/usr/bin/env', 'stud', '--version'], null, [
            'PATH' => $this->home . '/.local/bin:' . $this->fakeBin . ':' . (string) getenv('PATH'),
            'HOME' => $this->home,
        ]);
        $installedProcess->mustRun();
        $this->assertPortableOutputUsesBundle($installedProcess->getOutput(), $bundleRoot);
        self::assertFileDoesNotExist($this->workspace . '/php.log');
    }

    public function testPortableModeRejectsUnsupportedPlatform(): void
    {
        $this->writeUnameFake('Darwin', 'x86_64');

        $process = $this->runSetup(['--portable', '--skip-init']);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Portable install supports Linux amd64 / WSL2 and macOS Apple Silicon only', $process->getErrorOutput());
    }

    public function testPortableModeFailsWhenChecksumDoesNotMatch(): void
    {
        $artifactPath = $this->createPortableArchive('linux-amd64');
        $checksumPath = $this->workspace . '/checksums.txt';
        file_put_contents($checksumPath, str_repeat('0', 64) . "  stud-portable-9.8.7-linux-amd64.tar.gz\n");
        $this->writeCurlFake($artifactPath, $checksumPath);

        $process = $this->runSetup(['--portable', '--skip-init']);

        self::assertNotSame(0, $process->getExitCode());
        self::assertFileDoesNotExist($this->home . '/.local/share/stud-portable/linux-amd64/stud');
    }

    public function testFailsWhenLatestReleaseResponseHasNoTagName(): void
    {
        $this->writeCurlFakeWithApiResponse(<<<'JSON'
{
  "name": "v9.8.7"
}
JSON);

        $process = $this->runSetup(['--skip-init']);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Could not determine latest release version from GitHub API.', $process->getErrorOutput());
        self::assertStringNotContainsString('releases/download', file_get_contents($this->curlLog) ?: '');
    }

    /**
     * @param list<string> $arguments
     */
    protected function runSetup(array $arguments): Process
    {
        $root = dirname(__DIR__, 2);
        $path = $this->fakeBin . ':/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        $process = new Process(array_merge(['bash', $root . '/setup-stud.sh'], $arguments), $root, [
            'PATH' => $path,
            'HOME' => $this->home,
            'SHELL' => '/bin/bash',
        ]);
        $process->run();

        return $process;
    }

    protected function writeDefaultFakes(): void
    {
        $this->writeUnameFake('Linux', 'x86_64');
        $this->writePhpFake();
        $this->writeCurlFake(null, null);
    }

    protected function writeUnameFake(string $system, string $machine): void
    {
        $this->writeExecutable($this->fakeBin . '/uname', <<<SH
#!/usr/bin/env sh
case "\${1:-}" in
    -s) printf '%s\n' "$system" ;;
    -m) printf '%s\n' "$machine" ;;
    *) printf '%s\n' "$system" ;;
esac
SH);
    }

    protected function writePhpFake(): void
    {
        $this->writeExecutable($this->fakeBin . '/php', <<<SH
#!/usr/bin/env sh
printf '%s\n' "\$*" >> "{$this->workspace}/php.log"
case "\${1:-}" in
    -r) printf '8.2' ;;
    -m) printf 'xml\ncurl\nmbstring\n' ;;
esac
SH);
    }

    protected function writePhpFakeThatFailsIfCalled(): void
    {
        $this->writeExecutable($this->fakeBin . '/php', <<<SH
#!/usr/bin/env sh
printf '%s\n' "\$*" >> "{$this->workspace}/php.log"
exit 44
SH);
    }

    protected function writeCurlFake(?string $portableArtifactPath, ?string $checksumsPath): void
    {
        $this->writeCurlFakeWithApiResponse(<<<'JSON'
{
  "tag_name": "v9.8.7",
  "name": "v9.8.7"
}
JSON, $portableArtifactPath, $checksumsPath);
    }

    protected function writeCurlFakeWithApiResponse(
        string $apiResponse,
        ?string $portableArtifactPath = null,
        ?string $checksumsPath = null,
    ): void {
        $portableArtifactPath ??= '';
        $checksumsPath ??= '';
        $this->writeExecutable($this->fakeBin . '/curl', <<<SH
#!/usr/bin/env sh
out=""
url=""
while [ "\$#" -gt 0 ]; do
    case "\$1" in
        -o)
            out="\$2"
            shift 2
            ;;
        -*)
            shift
            ;;
        *)
            url="\$1"
            shift
            ;;
    esac
done
printf '%s\n' "\$url" >> "{$this->curlLog}"
case "\$url" in
    *api.github.com*)
        cat <<'JSON'
$apiResponse
JSON
        ;;
    *stud-9.8.7.phar)
        cat > "\$out" <<'STUD'
#!/usr/bin/env sh
printf 'stud 9.8.7\n'
STUD
        ;;
    *stud-portable-9.8.7-linux-amd64.tar.gz)
        cp "$portableArtifactPath" "\$out"
        ;;
    *checksums.txt)
        cp "$checksumsPath" "\$out"
        ;;
    *)
        printf 'unexpected curl url: %s\n' "\$url" >&2
        exit 1
        ;;
esac
SH);
    }

    protected function createPortableArchive(string $platform): string
    {
        $fixtureRoot = $this->workspace . '/portable-fixture-' . $platform;
        $artifactName = 'stud-portable-9.8.7-' . $platform;
        [$runtimePath, $pharPath] = $this->createPortableFixture($fixtureRoot);
        $buildRoot = $this->buildPortableFixture($platform, $artifactName, $pharPath, $runtimePath);

        $archivePath = $this->workspace . '/stud-portable-9.8.7-' . $platform . '.tar.gz';
        $archiveProcess = new Process(['tar', '-czf', $archivePath, '-C', $buildRoot, $artifactName]);
        $archiveProcess->mustRun();

        return $archivePath;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function createPortableFixture(string $fixtureRoot): array
    {
        $runtimePath = $fixtureRoot . '/runtime/php';
        $pharPath = $fixtureRoot . '/app/stud.phar';
        mkdir($fixtureRoot . '/runtime', 0777, true);
        mkdir($fixtureRoot . '/app', 0777, true);
        file_put_contents($pharPath, 'phar payload');
        $this->writeExecutable($runtimePath, <<<'SH'
#!/usr/bin/env sh
case "${2:-}" in
    --version)
        printf 'portable stud 9.8.7\nruntime=%s\nphar=%s\nargs=%s\n' "$0" "$1" "$2"
        ;;
    *)
        printf 'unexpected command: %s\n' "${2:-}" >&2
        exit 1
        ;;
esac
SH);

        return [$runtimePath, $pharPath];
    }

    protected function buildPortableFixture(
        string $platform,
        string $artifactName,
        string $pharPath,
        string $runtimePath,
    ): string {
        $buildRoot = $this->workspace . '/portable-build-' . $platform;
        $root = dirname(__DIR__, 2);
        $buildProcess = new Process([
            $root . '/scripts/build-portable',
            '--platform',
            $platform,
            '--phar',
            $pharPath,
            '--runtime',
            $runtimePath,
            '--output',
            $buildRoot,
            '--name',
            $artifactName,
        ], $root);
        $buildProcess->mustRun();

        return $buildRoot;
    }

    protected function assertPortableOutputUsesBundle(string $output, string $bundleRoot): void
    {
        self::assertStringContainsString('portable stud 9.8.7', $output);
        self::assertStringContainsString('runtime=' . $bundleRoot . '/runtime/php', $output);
        self::assertStringContainsString('phar=' . $bundleRoot . '/app/stud.phar', $output);
        self::assertStringContainsString('args=--version', $output);
    }

    protected function createChecksums(string $artifactPath): string
    {
        $checksumPath = $this->workspace . '/checksums.txt';
        file_put_contents($checksumPath, hash_file('sha256', $artifactPath) . '  ' . basename($artifactPath) . "\n");

        return $checksumPath;
    }

    protected function writeExecutable(string $path, string $content): void
    {
        file_put_contents($path, $content);
        chmod($path, 0755);
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
