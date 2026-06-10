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
    public function testHelpAgentStdoutIsExactlyOneJsonObject(): void
    {
        $result = $this->runAgentProcess(['help', '--agent'], []);

        self::assertSame(0, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertTrue($decoded['success'] ?? false);
    }

    public function testCacheClearAgentSuppressesLoggerOutput(): void
    {
        $home = $this->createTempHome();

        try {
            $result = $this->runAgentProcess(['cache:clear', '--agent'], [], ['HOME' => $home]);
        } finally {
            $this->removeDirectory($home);
        }

        self::assertSame(0, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertTrue($decoded['success'] ?? false);
    }

    public function testAgentListenerErrorIsExactlyOneJsonObject(): void
    {
        $home = $this->createTempHome();

        try {
            $result = $this->runAgentProcess(['status', '--agent'], [], ['HOME' => $home]);
        } finally {
            $this->removeDirectory($home);
        }

        self::assertSame(1, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertFalse($decoded['success'] ?? true);
        self::assertIsString($decoded['error'] ?? null);
        self::assertNotSame('', $decoded['error']);
    }

    public function testInvalidAgentInputErrorIsExactlyOneJsonObject(): void
    {
        $result = $this->runAgentProcess(['help', '--agent'], 'not json');

        self::assertSame(1, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertFalse($decoded['success'] ?? true);
        self::assertStringContainsString('Invalid JSON', (string) ($decoded['error'] ?? ''));
    }

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
        self::assertArrayHasKey('globalInput', $decoded['data']);
        self::assertArrayHasKey('responseSchemas', $decoded['data']);
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
        self::assertArrayHasKey('responseSchemas', $decoded['data']);
    }

    public function testHelpGeneralDiscoveryUsesCompactOutputReferences(): void
    {
        $decoded = $this->runHelpAgent([]);
        $commands = [];
        foreach ($decoded['data']['commands'] ?? [] as $command) {
            $commands[$command['name']] = $command;
        }

        self::assertSame(['successOnly', 'error'], $commands['commit']['output']['compact'] ?? null);
        self::assertSame(['successData', 'error'], $commands['commit']['output']['full'] ?? null);
        self::assertSame('Commit changes.', $commands['commit']['description'] ?? null);
        self::assertArrayHasKey('data', $commands['commit']['output'] ?? []);
        self::assertArrayNotHasKey('compactSuccess', $commands['commit']['output'] ?? []);
        self::assertArrayNotHasKey('success', $commands['commit']['output'] ?? []);
        self::assertArrayNotHasKey('compact', $commands['commit']['input']['properties'] ?? []);
        self::assertArrayNotHasKey('parameters', $commands['commit']);
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
        $decoded = $this->runHelpAgent(['command' => 'commit']);

        self::assertTrue($decoded['success'] ?? false);
        self::assertSame('commit', $decoded['data']['name'] ?? null);
        self::assertSame('Guides you through making a conventional commit', $decoded['data']['description'] ?? null);
        self::assertSame('bool', $decoded['data']['input']['properties']['compact']['type'] ?? null);
        self::assertTrue($decoded['data']['input']['properties']['compact']['default'] ?? false);
        self::assertTrue($decoded['data']['output']['compactSuccess']['success'] ?? false);
        self::assertArrayHasKey('diagnostics?', $decoded['data']['output']['compactSuccess'] ?? []);
    }

    public function testAgentCompactFlagDefaultsToTrue(): void
    {
        self::assertTrue(\_agent_compact_enabled([]));
        self::assertTrue(\_agent_compact_enabled(['compact' => true]));
    }

    public function testAgentCompactFlagCanRequestFullOutput(): void
    {
        self::assertFalse(\_agent_compact_enabled(['compact' => false]));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runHelpAgent(array $input): array
    {
        $result = $this->runAgentProcess(['help', '--agent'], $input);

        self::assertSame(0, $result['exitCode'], 'help --agent must exit 0. stderr: ' . $result['stderr']);

        return $this->assertSingleJsonObject($result['stdout']);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, mixed>|string $input
     * @param array<string, string> $env
     * @return array{exitCode: int|null, stdout: string, stderr: string}
     */
    private function runAgentProcess(array $arguments, array|string $input, array $env = []): array
    {
        $processInput = is_array($input) ? (string) json_encode($input) : $input;
        $proc = new \Symfony\Component\Process\Process(
            array_merge([__DIR__ . '/../../vendor/bin/castor'], $arguments),
            __DIR__ . '/../..',
            $env === [] ? null : $env,
            $processInput,
            15
        );
        $proc->run();

        return [
            'exitCode' => $proc->getExitCode(),
            'stdout' => $proc->getOutput(),
            'stderr' => $proc->getErrorOutput(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertSingleJsonObject(string $stdout): array
    {
        self::assertStringStartsWith('{', $stdout, 'stdout must be JSON from the first byte');
        self::assertMatchesRegularExpression('/}\n?$/', $stdout, 'stdout must end after one JSON object and optional newline');

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, 'stdout must decode as JSON without trimming prefixes or suffixes');

        $normalizedStdout = str_ends_with($stdout, "\n") ? substr($stdout, 0, -1) : $stdout;
        self::assertSame(
            json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $normalizedStdout,
            'stdout must contain exactly one minified JSON object'
        );

        return $decoded;
    }

    private function createTempHome(): string
    {
        $home = sys_get_temp_dir() . '/stud_agent_home_' . bin2hex(random_bytes(8));
        if (! mkdir($home, 0777, true) && ! is_dir($home)) {
            self::fail('Could not create temporary home directory');
        }

        return $home;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);

                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
