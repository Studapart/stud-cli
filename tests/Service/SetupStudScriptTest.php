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
        self::assertFileExists($this->home . '/.local/share/stud-portable/linux-amd64/stud');
        self::assertTrue(is_link($this->home . '/.local/bin/stud'));
        self::assertStringContainsString('portable stud 9.8.7', $process->getOutput());
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

    /**
     * @param list<string> $arguments
     */
    protected function runSetup(array $arguments): Process
    {
        $root = dirname(__DIR__, 2);
        $path = $this->fakeBin . ':' . (string) getenv('PATH');
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
        printf '{"tag_name":"v9.8.7"}\n'
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
        $artifactDir = $this->workspace . '/stud-portable-9.8.7-' . $platform;
        mkdir($artifactDir, 0777, true);
        $this->writeExecutable($artifactDir . '/stud', <<<'SH'
#!/usr/bin/env sh
printf 'portable stud 9.8.7\n'
SH);

        $archivePath = $this->workspace . '/stud-portable-9.8.7-' . $platform . '.tar.gz';
        $process = new Process(['tar', '-czf', $archivePath, '-C', $this->workspace, basename($artifactDir)]);
        $process->mustRun();

        return $archivePath;
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
