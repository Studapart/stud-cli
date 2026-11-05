<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CheckCrapScriptTest extends TestCase
{
    public function testPassesWhenCrapIsAtTheLimit(): void
    {
        $process = $this->runChecker($this->writeClover('10'));

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testFailsWhenCrapIsAboveTheLimit(): void
    {
        $process = $this->runChecker($this->writeClover('11'));

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('save crap 11', $process->getErrorOutput());
    }

    public function testRejectsAMissingFile(): void
    {
        $process = $this->runChecker($this->tempPath() . '-missing');

        self::assertSame(2, $process->getExitCode());
    }

    public function testAllowsABaselinedMethodAtItsRecordedScore(): void
    {
        $process = $this->runChecker($this->writeClover('11'), $this->writeBaseline('11'));

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testFailsWhenBaselinedCrapRises(): void
    {
        $process = $this->runChecker($this->writeClover('12'), $this->writeBaseline('11'));

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('save crap 12 above recorded 11', $process->getErrorOutput());
    }

    private function runChecker(string $cloverPath, ?string $baselinePath = null): Process
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/scripts/check-crap.php',
            $cloverPath,
        ];
        if ($baselinePath !== null) {
            $command[] = $baselinePath;
        }
        $process = new Process($command);
        $process->run();

        return $process;
    }

    private function writeClover(string $crap): string
    {
        $path = $this->tempPath();
        $xml = <<<XML
<?xml version="1.0"?>
<coverage>
  <file name="Example.php">
    <line num="10" type="method" name="save" crap="{$crap}"/>
  </file>
</coverage>
XML;
        file_put_contents($path, $xml);

        return $path;
    }

    private function writeBaseline(string $score): string
    {
        $path = $this->tempPath();
        file_put_contents($path, "Example.php save {$score}\n");

        return $path;
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crap');
        self::assertNotFalse($path);

        return $path;
    }
}
