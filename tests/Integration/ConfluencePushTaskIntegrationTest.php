<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for confluence:push task (castor layer).
 *
 * Verifies agent mode file handling: non-readable file path yields JSON error and exit 1.
 * CLI update with --file uses the same content-loading branch as create (see castor.php);
 * handler tests cover update flow with content.
 */
#[Group('integration')]
class ConfluencePushTaskIntegrationTest extends TestCase
{
    public function testAgentModeWithFileNotReadableOutputsErrorAndExits(): void
    {
        $input = (string) json_encode([
            'file' => '/nonexistent/' . bin2hex(random_bytes(8)) . '.md',
            'page' => '123',
            'url' => 'https://example.com',
        ]);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'confluence:push',
                '--agent',
            ],
            __DIR__ . '/../..',
            null,
            null,
            15
        );
        $proc->setInput($input);
        $proc->run();
        $stdout = $proc->getOutput();
        $stderr = $proc->getErrorOutput();
        $fullOutput = $stdout . $stderr;
        $decoded = null;
        foreach (array_reverse(explode("\n", trim($fullOutput))) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') {
                $decoded = json_decode($line, true);

                break;
            }
        }

        self::assertSame(1, $proc->getExitCode(), 'Expected exit code 1 when file is not readable. Output: ' . $fullOutput);
        self::assertIsArray($decoded, 'Expected JSON line in output. Output: ' . $fullOutput);
        self::assertFalse($decoded['success'] ?? true);
        self::assertArrayHasKey('error', $decoded);
        self::assertStringContainsString('Cannot read file', $decoded['error'] ?? '');
    }
}
