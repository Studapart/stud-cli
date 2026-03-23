<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AgentModeSchemaGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixtures/schema_generator_fixtures.php';

/**
 * Tests the runtime schema generator and validates that its output stays
 * in sync with the actual #[AsTask] functions defined in castor.php.
 *
 * Because PHPUnit loads castor.php via the autoloader / bootstrap, all
 * task functions are already defined and available for reflection.
 */
class AgentModeSchemaGeneratorTest extends TestCase
{
    private AgentModeSchemaGenerator $generator;

    /** @var array{meta: array<string, string>, commands: list<array<string, mixed>>} */
    private array $schema;

    /** @var array<string, array{name: string, aliases: list<string>, hasAgent: bool, options: list<string>, arguments: list<string>}> */
    private array $taskDefs;

    protected function setUp(): void
    {
        parent::setUp();

        $castorPath = dirname(__DIR__, 2) . '/castor.php';
        $this->assertTrue(is_readable($castorPath), 'castor.php not found');

        $this->taskDefs = $this->parseCastorTasksFromSource($castorPath);
        $this->assertNotEmpty($this->taskDefs, 'No tasks parsed from castor.php');

        $this->generator = new AgentModeSchemaGenerator();
        $this->schema = $this->generator->generate();
    }

    public function testGenerateReturnsValidStructure(): void
    {
        $this->assertArrayHasKey('meta', $this->schema);
        $this->assertArrayHasKey('commands', $this->schema);
        $this->assertNotEmpty($this->schema['commands']);
    }

    public function testEveryCommandHasRequiredKeys(): void
    {
        $requiredKeys = ['name', 'aliases', 'description', 'parameters', 'input', 'output'];
        foreach ($this->schema['commands'] as $cmd) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $cmd, "Command '{$cmd['name']}' missing key '{$key}'");
            }
            $this->assertArrayHasKey('options', $cmd['parameters']);
            $this->assertArrayHasKey('arguments', $cmd['parameters']);
            $this->assertArrayHasKey('properties', $cmd['input']);
        }
    }

    public function testSchemaCoversAllAgentTasks(): void
    {
        $schemaNames = array_column($this->schema['commands'], 'name');
        $taskNames = array_keys($this->taskDefs);

        $missingFromSchema = array_diff($taskNames, $schemaNames);
        $this->assertEmpty(
            $missingFromSchema,
            'Tasks with --agent missing from generated schema: ' . implode(', ', $missingFromSchema)
        );
    }

    public function testSchemaHasNoStaleCommands(): void
    {
        $schemaNames = array_column($this->schema['commands'], 'name');
        $taskNames = array_keys($this->taskDefs);

        $stale = array_diff($schemaNames, $taskNames);
        $stale = array_filter($stale, fn (string $n): bool => ! str_starts_with($n, 'test:'));
        $this->assertEmpty(
            $stale,
            'Schema contains commands not in castor.php: ' . implode(', ', $stale)
        );
    }

    public function testAliasesMatch(): void
    {
        $schemaByName = [];
        foreach ($this->schema['commands'] as $cmd) {
            $schemaByName[$cmd['name']] = $cmd;
        }

        foreach ($this->taskDefs as $name => $def) {
            if (! isset($schemaByName[$name])) {
                continue;
            }
            $expected = $def['aliases'];
            $actual = $schemaByName[$name]['aliases'];
            sort($expected);
            sort($actual);
            $this->assertSame(
                $expected,
                $actual,
                "Aliases mismatch for '{$name}'"
            );
        }
    }

    public function testNonAgentOptionsReflectedInParameters(): void
    {
        $agentOnly = ['agent', 'inputFile'];
        $schemaByName = [];
        foreach ($this->schema['commands'] as $cmd) {
            $schemaByName[$cmd['name']] = $cmd;
        }

        foreach ($this->taskDefs as $name => $def) {
            if (! isset($schemaByName[$name])) {
                continue;
            }
            $flat = strtolower(
                implode(' ', $schemaByName[$name]['parameters']['options'] ?? [])
                . ' '
                . implode(' ', $schemaByName[$name]['parameters']['arguments'] ?? [])
            );

            foreach ($def['options'] as $opt) {
                if (in_array($opt, $agentOnly, true)) {
                    continue;
                }
                $this->assertStringContainsString(
                    strtolower($opt),
                    $flat,
                    "Option '--{$opt}' of '{$name}' not in generated schema parameters"
                );
            }

            foreach ($def['arguments'] as $arg) {
                if (in_array($arg, $agentOnly, true)) {
                    continue;
                }
                $this->assertStringContainsString(
                    strtolower($arg),
                    $flat,
                    "Argument '{$arg}' of '{$name}' not in generated schema parameters"
                );
            }
        }
    }

    public function testEveryAgentTaskHasAgentOption(): void
    {
        foreach ($this->taskDefs as $name => $def) {
            $this->assertTrue($def['hasAgent'], "Task '{$name}' missing --agent option");
        }
    }

    public function testCommandsAreSortedByName(): void
    {
        $names = array_column($this->schema['commands'], 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names, 'Commands should be sorted alphabetically');
    }

    public function testGeneratorSkipsDefaultAndAgentlessTasks(): void
    {
        $schema = $this->schema;
        $names = array_column($schema['commands'], 'name');
        $this->assertNotContains('', $names, 'Default/nameless tasks must be excluded');
        foreach ($schema['commands'] as $cmd) {
            $optionFlat = implode(' ', $cmd['parameters']['options'] ?? []);
            $this->assertStringNotContainsString('agent', $optionFlat, "Command '{$cmd['name']}' should not list --agent in its schema parameters");
        }
    }

    public function testGeneratorSkipsNonTaskFunctions(): void
    {
        $schema = $this->generator->generate(['_load_constants', '_get_config']);
        $this->assertEmpty($schema['commands'], 'Non-task functions must not appear in schema');
    }

    public function testGeneratorSkipsDefaultTask(): void
    {
        $schema = $this->generator->generate(['main']);
        $this->assertEmpty($schema['commands'], 'Default task (main) must be excluded');
    }

    public function testGeneratorEmptyForNonTaskHelpers(): void
    {
        $schema = $this->generator->generate(['_items_create_normalize_summary']);
        $this->assertEmpty($schema['commands'], 'Non-task helper functions must be excluded');
    }

    public function testOutputSchemaOmittedWhenNoAttribute(): void
    {
        $schema = $this->generator->generate(['_test_fixture_no_output_attr']);
        $cmd = $schema['commands'][0];
        $this->assertNull($cmd['output']['description']);
        $this->assertSame('(undescribed)', $cmd['output']['success']['data']);
    }

    public function testOutputSchemaUndescribedWhenEmptyAttribute(): void
    {
        $schema = $this->generator->generate(['_test_fixture_empty_output_attr']);
        $cmd = $schema['commands'][0];
        $this->assertNull($cmd['output']['description']);
        $this->assertSame('(undescribed)', $cmd['output']['success']['data']);
    }

    public function testOutputSchemaWithResponseClass(): void
    {
        $schemaByName = [];
        foreach ($this->schema['commands'] as $cmd) {
            $schemaByName[$cmd['name']] = $cmd;
        }

        $cmd = $schemaByName['config:show'];
        $this->assertIsArray($cmd['output']['success']['data']);
        $this->assertNotEmpty($cmd['output']['success']['data']);
        $this->assertNotNull($cmd['output']['description']);
    }

    public function testOutputSchemaWithExplicitProperties(): void
    {
        $schemaByName = [];
        foreach ($this->schema['commands'] as $cmd) {
            $schemaByName[$cmd['name']] = $cmd;
        }

        $cmd = $schemaByName['config:init'];
        $this->assertSame(['message' => 'string'], $cmd['output']['success']['data']);
        $this->assertNotNull($cmd['output']['description']);
    }

    public function testOutputSchemaHasErrorStructure(): void
    {
        foreach ($this->schema['commands'] as $cmd) {
            $this->assertArrayHasKey('error', $cmd['output'], "Command '{$cmd['name']}' output missing error structure");
            $this->assertSame(['success' => false, 'error' => 'string'], $cmd['output']['error']);
        }
    }

    public function testInputPropertiesExcludeAgentParams(): void
    {
        foreach ($this->schema['commands'] as $cmd) {
            $props = array_keys($cmd['input']['properties'] ?? []);
            $this->assertNotContains('agent', $props, "'{$cmd['name']}' input should not contain 'agent'");
            $this->assertNotContains('inputFile', $props, "'{$cmd['name']}' input should not contain 'inputFile'");
        }
    }

    public function testSubmitInputIncludesStageAllAndPushRelatedProperties(): void
    {
        $schemaByName = [];
        foreach ($this->schema['commands'] as $cmd) {
            $schemaByName[$cmd['name']] = $cmd;
        }
        $this->assertArrayHasKey('submit', $schemaByName);
        $props = $schemaByName['submit']['input']['properties'] ?? [];
        foreach (['stageAll', 'isNew', 'message', 'pleaseFallback'] as $key) {
            $this->assertArrayHasKey($key, $props, 'submit agent input must include "' . $key . '" property');
        }
        $this->assertArrayNotHasKey('noPlease', $props, 'submit agent input must not include redundant noPlease; use pleaseFallback');
    }

    public function testPushAgentInputHasPleaseFallbackNotNoPlease(): void
    {
        $schemaByName = [];
        foreach ($this->schema['commands'] as $cmd) {
            $schemaByName[$cmd['name']] = $cmd;
        }
        $this->assertArrayHasKey('push', $schemaByName);
        $props = $schemaByName['push']['input']['properties'] ?? [];
        $this->assertArrayHasKey('pleaseFallback', $props);
        $this->assertArrayNotHasKey('noPlease', $props, 'push agent JSON uses pleaseFallback only; CLI retains --no-please');
    }

    public function testConfluencePushInputIncludesFileAndContentProperties(): void
    {
        $schemaByName = [];
        foreach ($this->schema['commands'] as $cmd) {
            $schemaByName[$cmd['name']] = $cmd;
        }
        $this->assertArrayHasKey('confluence:push', $schemaByName);
        $props = $schemaByName['confluence:push']['input']['properties'] ?? [];
        $this->assertArrayHasKey('file', $props, 'confluence:push agent input must include "file" property');
        $this->assertArrayHasKey('content', $props, 'confluence:push agent input must include "content" property');
        $this->assertSame('string', $props['file']['type'] ?? null);
        $this->assertSame('string', $props['content']['type'] ?? null);
    }

    public function testConfluenceShowInSchemaWithExpectedOutput(): void
    {
        $schemaByName = [];
        foreach ($this->schema['commands'] as $cmd) {
            $schemaByName[$cmd['name']] = $cmd;
        }
        $this->assertArrayHasKey('confluence:show', $schemaByName);
        $cmd = $schemaByName['confluence:show'];
        $this->assertContains('csh', $cmd['aliases'] ?? []);
        $outputSuccess = $cmd['output']['success']['data'] ?? [];
        $this->assertArrayHasKey('id', $outputSuccess, 'confluence:show agent output must include id');
        $this->assertArrayHasKey('title', $outputSuccess);
        $this->assertArrayHasKey('url', $outputSuccess);
        $this->assertArrayHasKey('body', $outputSuccess);
    }

    // ------------------------------------------------------------------
    // Source-based parsing (independent verification, no reflection)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{name: string, aliases: list<string>, hasAgent: bool, options: list<string>, arguments: list<string>}>
     */
    private function parseCastorTasksFromSource(string $path): array
    {
        $source = (string) file_get_contents($path);
        $tasks = [];

        $pattern = '/#\[AsTask\((.*?)\)\]\s*\nfunction\s+\w+\((.*?)\)\s*:\s*\w+\s*\{/s';
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $attrBody = $m[1];
            $funcParams = $m[2];

            if (preg_match("/name:\s*'([^']+)'/", $attrBody, $nameMatch)) {
                $name = $nameMatch[1];
            } else {
                continue;
            }

            $aliases = [];
            if (preg_match("/aliases:\s*\[([^\]]*)\]/", $attrBody, $aliasMatch)) {
                preg_match_all("/'([^']+)'/", $aliasMatch[1], $innerMatches);
                $aliases = $innerMatches[1] ?? [];
            }

            $hasAgent = str_contains($funcParams, "name: 'agent'");

            $options = [];
            preg_match_all("/#\[AsOption\(name:\s*'([^']+)'/", $funcParams, $optMatches);
            $options = $optMatches[1] ?? [];

            $arguments = [];
            preg_match_all("/#\[AsArgument\(name:\s*'([^']+)'/", $funcParams, $argMatches);
            $arguments = $argMatches[1] ?? [];

            if (! $hasAgent) {
                continue;
            }

            $tasks[$name] = [
                'name' => $name,
                'aliases' => $aliases,
                'hasAgent' => $hasAgent,
                'options' => $options,
                'arguments' => $arguments,
            ];
        }

        return $tasks;
    }
}
