<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for items:upload in agent mode (castor layer).
 */
#[Group('integration')]
class ItemsUploadAgentIntegrationTest extends TestCase
{
    public function testAgentModeWithPositionalKeyReadsStdinAndDoesNotTreatKeyAsFilePath(): void
    {
        $input = (string) json_encode([
            'key' => 'SCI-99',
            'files' => ['README.md'],
        ]);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'items:upload',
                'SCI-99',
                '--agent',
            ],
            __DIR__ . '/../..',
            null,
            null,
            30
        );
        $proc->setInput($input);
        $proc->run();
        $stdout = $proc->getOutput();
        $stderr = $proc->getErrorOutput();
        $fullOutput = $stdout . $stderr;

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
            'files' => ['README.md'],
        ]);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'items:upload',
                '--agent',
            ],
            __DIR__ . '/../..',
            null,
            null,
            30
        );
        $proc->setInput($input);
        $proc->run();
        $stdout = $proc->getOutput();
        $stderr = $proc->getErrorOutput();
        $fullOutput = $stdout . $stderr;

        self::assertStringNotContainsString('Cannot read input file', $fullOutput);
        self::assertStringNotContainsString('item.upload.error_no_key', $fullOutput);
    }
}
