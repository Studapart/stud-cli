<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BuildPharScriptTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testDryRunResolvesCastorFromLockAndUsesReleaseAssetUrl(): void
    {
        $process = new Process(
            [
                $this->repoRoot . '/scripts/build-phar',
                '--version',
                '4.0.0-dev',
                '--output',
                $this->repoRoot . '/.cursor/tmp/stud-dry-run.phar',
                '--dry-run',
            ],
            $this->repoRoot
        );
        $process->mustRun();

        $output = $process->getOutput();
        self::assertMatchesRegularExpression('/^castor_tag=v\d+\.\d+\.\d+$/m', $output);
        self::assertMatchesRegularExpression(
            '#^castor_url=https://github\.com/jolicode/castor/releases/download/v\d+\.\d+\.\d+/castor\.linux-amd64\.phar$#m',
            $output
        );
        self::assertStringNotContainsString('api.github.com', $output);
        self::assertStringContainsString('--castor-phar', $output);
        self::assertStringContainsString('--castor-version', $output);
        self::assertStringContainsString('--app-version 4.0.0-dev', $output);
    }

    public function testDryRunHonorsCastorPharEnvOverride(): void
    {
        $override = $this->repoRoot . '/.cursor/tmp/castor-override-test.phar';
        if (! is_dir(dirname($override))) {
            mkdir(dirname($override), 0777, true);
        }
        file_put_contents($override, 'fake-castor-phar');

        try {
            $process = new Process(
                [
                    $this->repoRoot . '/scripts/build-phar',
                    '--version',
                    '4.0.0-dev',
                    '--output',
                    $this->repoRoot . '/.cursor/tmp/stud-dry-run.phar',
                    '--dry-run',
                ],
                $this->repoRoot,
                ['CASTOR_PHAR' => $override]
            );
            $process->mustRun();

            $output = $process->getOutput();
            self::assertStringContainsString('castor_phar=' . $override, $output);
            self::assertStringContainsString('--castor-phar ' . $override, $output);
            self::assertStringContainsString('releases/download/', $output);
        } finally {
            if (is_file($override)) {
                unlink($override);
            }
        }
    }
}
