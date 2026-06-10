<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for agent-mode parameter parity (SCI-80).
 *
 * Verifies that commands that support --agent accept the same parameters via JSON
 * as via CLI: config:show (quiet), items:create (fields as string), help (commandName/command).
 */
#[Group('integration')]
class AgentModeParityIntegrationTest extends TestCase
{
    public function testConfigShowAcceptsQuietInAgentMode(): void
    {
        $input = (string) json_encode([
            'key' => 'jira.url',
            'quiet' => true,
        ]);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'config:show',
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

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, 'Output must be valid JSON. stderr: ' . $stderr);
        self::assertArrayHasKey('success', $decoded);
        // Exit 0 when success, 1 when config missing or key invalid; in both cases quiet was accepted (parity).
        self::assertContains($proc->getExitCode(), [0, 1]);
    }

    public function testItemsCreateAcceptsFieldsAsStringInAgentMode(): void
    {
        $input = (string) json_encode([
            'project' => 'SCI',
            'type' => 'Task',
            'summary' => 'Parity test',
            'fields' => 'labels=TestLabel',
        ]);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'items:create',
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

        // Parity: "fields" as string must be read and passed to handler (no file read error, no undefined key).
        self::assertStringNotContainsString('Cannot read input file', $fullOutput);
        self::assertStringNotContainsString('Undefined array key "fields"', $fullOutput);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, 'Output must be valid JSON');
    }

    public function testHelpAcceptsCommandNameInAgentMode(): void
    {
        $input = (string) json_encode(['commandName' => 'config:show']);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'help',
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

        self::assertSame(0, $proc->getExitCode(), 'help --agent with commandName must exit 0. stderr: ' . $stderr);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success'] ?? false);
        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('name', $decoded['data']);
        self::assertSame('config:show', $decoded['data']['name']);
    }

    public function testHelpAcceptsCommandAliasInAgentMode(): void
    {
        $input = (string) json_encode(['command' => 'co']);

        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'help',
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

        self::assertSame(0, $proc->getExitCode(), 'help --agent with command (alias) must exit 0. stderr: ' . $stderr);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['success'] ?? false);
        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('name', $decoded['data']);
        self::assertSame('commit', $decoded['data']['name']);
    }

    public function testHelpDefaultsToEssentialCommandsInAgentMode(): void
    {
        $decoded = $this->runHelpAgent([]);

        self::assertTrue($decoded['success'] ?? false);
        $names = array_column($decoded['data']['commands'] ?? [], 'name');
        self::assertContains('commit', $names);
        self::assertContains('items:show', $names);
        self::assertNotContains('cache:clear', $names);
    }

    public function testHelpCanReturnFullSchemaInAgentMode(): void
    {
        $decoded = $this->runHelpAgent(['essential' => false]);

        self::assertTrue($decoded['success'] ?? false);
        $names = array_column($decoded['data']['commands'] ?? [], 'name');
        self::assertContains('commit', $names);
        self::assertContains('cache:clear', $names);
    }

    public function testHelpCommandLookupIgnoresEssentialFilterInAgentMode(): void
    {
        $decoded = $this->runHelpAgent(['command' => 'cc']);

        self::assertTrue($decoded['success'] ?? false);
        self::assertSame('cache:clear', $decoded['data']['name'] ?? null);
        self::assertFalse($decoded['data']['essential'] ?? true);
    }

    public function testHelpDocumentsCompactAgentInputAndOutputShape(): void
    {
        $decoded = $this->runHelpAgent(['command' => 'commit', 'compact' => true]);

        self::assertTrue($decoded['success'] ?? false);
        self::assertSame('commit', $decoded['data']['name'] ?? null);
        self::assertSame('bool', $decoded['data']['input']['properties']['compact']['type'] ?? null);
        self::assertSame(['success' => true], $decoded['data']['output']['compactSuccess'] ?? null);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runHelpAgent(array $input): array
    {
        $proc = new \Symfony\Component\Process\Process(
            [
                __DIR__ . '/../../vendor/bin/castor',
                'help',
                '--agent',
            ],
            __DIR__ . '/../..',
            null,
            (string) json_encode($input),
            15
        );
        $proc->run();
        $stdout = $proc->getOutput();
        $stderr = $proc->getErrorOutput();

        self::assertSame(0, $proc->getExitCode(), 'help --agent must exit 0. stderr: ' . $stderr);
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, 'Output must be valid JSON. stderr: ' . $stderr);

        return $decoded;
    }
}
