<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Config\GlobalStudConfigFieldMap;
use App\Config\ProjectStudConfigFieldMap;
use App\Service\AgentModeInputSchemaBuilder;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AgentModeInputSchemaBuilderTest extends TestCase
{
    private AgentModeInputSchemaBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new AgentModeInputSchemaBuilder();
    }

    public function testBuildCollectsOptionAndArgumentLabelsAndSkipsAgentOnlyParams(): void
    {
        $input = $this->builder->build('custom:task', $this->params(static function (
            #[AsArgument]
            string $key,
            #[AsArgument(name: 'inputFile')]
            ?string $file = null,
            #[AsArgument(name: 'target')]
            ?string $target = null,
            #[AsOption(name: 'agent')]
            bool $agent = false,
            #[AsOption(name: 'project', shortcut: 'p')]
            ?string $project = null,
            #[AsOption]
            bool $quiet = false,
        ): void {
        }));

        $this->assertSame(['project (p)', 'quiet'], $input['options']);
        $this->assertSame(['key', 'target (optional)'], $input['arguments']);
        $this->assertSame([
            'key' => ['type' => 'string'],
            'target' => ['type' => 'string|null', 'optional' => true, 'default' => null],
            'project' => ['type' => 'string|null', 'optional' => true, 'default' => null],
            'quiet' => ['type' => 'bool', 'optional' => true, 'default' => false],
        ], $input['properties']);
    }

    public function testBuildFallsBackToMixedForUntypedMixedAndUnionTypes(): void
    {
        $input = $this->builder->build('custom:task', $this->params(static function (
            #[AsOption]
            mixed $anything = null,
            #[AsOption]
            int|string $either = 1,
            #[AsArgument]
            string ...$rest,
        ): void {
        }));

        $this->assertSame([
            'anything' => ['type' => 'mixed', 'optional' => true, 'default' => null],
            'either' => ['type' => 'mixed', 'optional' => true, 'default' => 1],
            'rest' => ['type' => 'string', 'optional' => true],
        ], $input['properties']);
    }

    /**
     * @param list<string> $expectedPrefix
     */
    #[DataProvider('prependedTaskProvider')]
    public function testBuildPrependsAgentOnlyInputPropertiesPerTask(string $taskName, array $expectedPrefix): void
    {
        $properties = $this->builder->build($taskName, $this->quietOnlyParams())['properties'];

        $this->assertSame(
            [...$expectedPrefix, 'quiet'],
            array_slice(array_keys($properties), 0, count($expectedPrefix) + 1),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function prependedTaskProvider(): iterable
    {
        yield 'confluence:push' => ['confluence:push', ['file', 'content']];
        yield 'submit' => ['submit', ['stageAll', 'isNew', 'message', 'pleaseFallback']];
        yield 'items:download' => ['items:download', ['issueKey']];
        yield 'items:upload' => ['items:upload', ['issueKey']];
        yield 'help' => ['help', ['commandName', 'command', 'essential']];
        yield 'plain task' => ['custom:task', []];
    }

    public function testBuildReplacesNoPleaseWithPleaseFallbackAndAddsProviderForPush(): void
    {
        $properties = $this->builder->build('push', $this->params(static function (
            #[AsOption(name: 'no-please')]
            bool $noPlease = false,
            #[AsOption]
            bool $quiet = false,
        ): void {
        }))['properties'];

        $this->assertSame([
            'pleaseFallback' => ['type' => 'bool', 'optional' => true, 'default' => true],
            'quiet' => ['type' => 'bool', 'optional' => true, 'default' => false],
            'provider' => ['type' => 'string|null', 'optional' => true, 'default' => null, 'enum' => ['jira', 'linear']],
        ], $properties);
    }

    public function testBuildAddsProviderOverrideOnlyForProviderCommands(): void
    {
        foreach (AgentModeInputSchemaBuilder::COMMANDS_WITH_PROVIDER_OVERRIDE as $taskName) {
            $properties = $this->builder->build($taskName, $this->quietOnlyParams())['properties'];
            $this->assertSame('provider', array_key_last($properties), $taskName);
        }

        $this->assertArrayNotHasKey('provider', $this->builder->build('custom:task', $this->quietOnlyParams())['properties']);
    }

    public function testBuildReplacesConfigInitInputWithGlobalConfigFields(): void
    {
        $properties = $this->builder->build('config:init', $this->quietOnlyParams())['properties'];

        $this->assertSame(array_keys(GlobalStudConfigFieldMap::INPUT_TO_YAML), array_keys($properties));
        $this->assertSame(['type' => 'array', 'optional' => true, 'default' => null], $properties['gitProviders']);
        $this->assertSame(['type' => 'array', 'optional' => true, 'default' => null], $properties['issueTrackerProviders']);
        $this->assertSame(['type' => 'bool', 'optional' => true, 'default' => null], $properties['jiraTransitionEnabled']);
        $this->assertSame(['type' => 'string|null', 'optional' => true, 'default' => null], $properties['jiraUrl']);
    }

    public function testBuildReplacesConfigProjectInitInputWithProjectConfigFields(): void
    {
        $properties = $this->builder->build('config:project-init', $this->quietOnlyParams())['properties'];

        $this->assertSame(
            [...array_keys(ProjectStudConfigFieldMap::INPUT_TO_YAML), 'skipBaseBranchRemoteCheck'],
            array_keys($properties),
        );
        $this->assertSame(['type' => 'int|null', 'optional' => true, 'default' => null], $properties['transitionId']);
        $this->assertSame(['type' => 'object', 'optional' => true, 'default' => null], $properties['linearTypeBranchPrefixes']);
        $this->assertSame(['type' => 'array', 'optional' => true, 'default' => null], $properties['workItemLabels']);
        $this->assertSame(['type' => 'array', 'optional' => true, 'default' => null], $properties['pullRequestLabels']);
        $this->assertSame(['type' => 'string|null', 'optional' => true, 'default' => null], $properties['projectKey']);
        $this->assertSame(['type' => 'bool', 'optional' => true, 'default' => false], $properties['skipBaseBranchRemoteCheck']);
    }

    /**
     * @return \ReflectionParameter[]
     */
    private function params(\Closure $task): array
    {
        return (new \ReflectionFunction($task))->getParameters();
    }

    /**
     * @return \ReflectionParameter[]
     */
    private function quietOnlyParams(): array
    {
        return $this->params(static function (
            #[AsOption]
            bool $quiet = false,
        ): void {
        });
    }
}
