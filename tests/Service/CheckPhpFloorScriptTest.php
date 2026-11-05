<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CheckPhpFloorScriptTest extends TestCase
{
    public function testRejectsAnUnpatchedLine(): void
    {
        foreach (['8.3.21', '8.4.27', '8.5.11'] as $version) {
            $process = $this->runFloor($version);

            self::assertSame(1, $process->getExitCode(), $version);
            self::assertStringContainsString('PHP 8.5.12 or newer is required.', $process->getErrorOutput());
        }
    }

    public function testAcceptsAPatchedLine(): void
    {
        foreach (['8.5.12', '8.6.0'] as $version) {
            $process = $this->runFloor($version);

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . ' ' . $version);
        }
    }

    private function runFloor(string $version): Process
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/scripts/check-php-floor.php',
            $version,
        ]);
        $process->run();

        return $process;
    }
}
