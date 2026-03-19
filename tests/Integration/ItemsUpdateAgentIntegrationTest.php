<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for items:update task in agent mode (castor layer).
 *
 * Verifies that agent-mode JSON input (key and fields object) is read from stdin
 * and applied. When a positional key is given (e.g. stud iu SCI-79 --agent), the
 * task must read from stdin, not treat the key as an input file path.
 */
#[Group('integration')]
class ItemsUpdateAgentIntegrationTest extends TestCase
{
    public function testAgentModeWithPositionalKeyReadsStdinAndDoesNotTreatKeyAsFilePath(): void
    {
        $input = (string) json_encode([
            'key' => 'SCI-99',
            'fields' => ['labels' => ['TestLabel']],
        ]);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'items:update',
                'SCI-99',
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

        // Before fix: _read_agent_input($key) tried to read file "SCI-99" and failed with "Cannot read input file".
        // After fix: we read from stdin; we may get editmeta/update or config errors, but not file read error.
        self::assertStringNotContainsString(
            'Cannot read input file',
            $fullOutput,
            'Agent mode must read JSON from stdin when no inputFile is given; key must not be used as file path. Output: ' . $fullOutput
        );
    }

    public function testAgentModeWithNoPositionalReadsStdinAndUsesKeyFromJson(): void
    {
        $input = (string) json_encode([
            'key' => 'SCI-98',
            'fields' => ['labels' => ['A', 'B']],
        ]);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'items:update',
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

        // Must read from stdin; must not fail with "Cannot read input file" or "error_no_key" (key is in JSON).
        self::assertStringNotContainsString('Cannot read input file', $fullOutput);
        self::assertStringNotContainsString('item.update.error_no_key', $fullOutput);
    }
}
