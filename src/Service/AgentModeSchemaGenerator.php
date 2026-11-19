<?php

declare(strict_types=1);

namespace App\Service;

use App\Attribute\AgentCommand;
use App\Attribute\AgentOutput;
use App\Config\ProjectStudConfigKeys;
use Castor\Attribute\AsTask;

/**
 * Generates the agent-mode schema at runtime by reflecting on
 * every #[AsTask]-annotated function that carries an --agent option.
 *
 * Works identically in development (castor.php sourced directly)
 * and inside the PHAR (functions already loaded).
 */
class AgentModeSchemaGenerator
{
    /**
     * @return list<string>
     */
    public static function commandsWithProviderOverride(): array
    {
        return AgentModeInputSchemaBuilder::COMMANDS_WITH_PROVIDER_OVERRIDE;
    }

    public const GENERATED_BY = 'AgentModeSchemaGenerator (runtime reflection)';

    private const EXPANDED_META = [
        'description' => 'Agent-mode schema — auto-generated from command signatures. When --agent is passed, input is a single JSON document (stdin or one positional file path) and output is a single JSON document.',
        'generatedBy' => self::GENERATED_BY,
        'defaultDiscovery' => 'help --agent with empty input returns essential command schemas only.',
        'fullDiscovery' => 'Use {"essential": false} with help --agent to return every command schema.',
        'commandDiscovery' => 'Use {"command":"<name-or-alias>"} with help --agent for expanded command response data.',
    ];

    private readonly AgentModeCompactSchemaFormatter $compactFormatter;

    public function __construct(
        private readonly AgentModeInputSchemaBuilder $inputSchemaBuilder,
        ?TranslationService $translator = null
    ) {
        $this->compactFormatter = new AgentModeCompactSchemaFormatter($translator);
    }

    /**
     * @param list<string>|null $functionNames Override function list (for testing); null = all user-defined functions.
     * @return array{meta: array<string, string>, commands: list<array<string, mixed>>}
     */
    public function generate(?array $functionNames = null, bool $essentialOnly = false, bool $expandedOutput = true): array
    {
        $commands = [];
        foreach ($this->discoverTasks($functionNames) as $taskDef) {
            if ($essentialOnly && $taskDef['essential'] !== true) {
                continue;
            }
            $commands[] = $expandedOutput ? $taskDef : $this->compactFormatter->command($taskDef);
        }

        usort($commands, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        if ($expandedOutput) {
            return ['meta' => self::EXPANDED_META, 'commands' => $commands];
        }

        return ['meta' => $this->compactFormatter->meta(), 'commands' => $commands] + $this->compactFormatter->globalSections();
    }

    /**
     * @param list<string>|null $functionNames
     * @return \Generator<array<string, mixed>>
     */
    private function discoverTasks(?array $functionNames): \Generator
    {
        $userFunctions = $functionNames ?? get_defined_functions()['user'];

        foreach ($userFunctions as $funcName) {
            $rf = new \ReflectionFunction($funcName);
            $taskAttrs = $rf->getAttributes(AsTask::class);
            if ($taskAttrs === []) {
                continue;
            }

            /** @var AsTask $task */
            $task = $taskAttrs[0]->newInstance();
            if ($task->default || $task->name === '') {
                continue;
            }

            $outputAttr = $rf->getAttributes(AgentOutput::class);
            $agentOutput = $outputAttr !== [] ? $outputAttr[0]->newInstance() : null;
            $commandAttr = $rf->getAttributes(AgentCommand::class);
            $agentCommand = $commandAttr !== [] ? $commandAttr[0]->newInstance() : null;
            yield $this->buildCommandEntry($task, $rf->getParameters(), $agentOutput, $agentCommand);
        }
    }

    /**
     * @param \ReflectionParameter[] $params
     * @return array<string, mixed>
     */
    private function buildCommandEntry(
        AsTask $task,
        array $params,
        ?AgentOutput $agentOutput,
        ?AgentCommand $agentCommand,
    ): array {
        $input = $this->inputSchemaBuilder->build($task->name, $params);

        return [
            'name' => $task->name,
            'aliases' => $task->aliases,
            'essential' => $agentCommand instanceof AgentCommand && $agentCommand->essential,
            'description' => $task->description,
            'parameters' => ['options' => $input['options'], 'arguments' => $input['arguments']],
            'input' => ['properties' => ['compact' => AgentModeCompactSchemaFormatter::COMPACT_PROPERTY] + $input['properties']],
            'output' => $this->buildOutputSchema($task->name, $agentOutput),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOutputSchema(string $taskName, ?AgentOutput $agentOutput): array
    {
        $dataProperties = $this->resolveOutputProperties($agentOutput);
        $diagnostics = [
            'diagnostics?' => [
                'errors?' => 'list<{message:string,technicalDetails?:string,context?:object}>',
                'warnings?' => 'list<{message:string,technicalDetails?:string,context?:object}>',
                'notices?' => 'list<{message:string,technicalDetails?:string,context?:object}>',
                'info?' => 'list<{message:string,technicalDetails?:string,context?:object}>',
            ],
        ];
        $successSchema = ['success' => true, 'data' => $dataProperties] + $diagnostics;
        $compactSuccessSchema = $agentOutput?->completionOnly === true
            ? ['success' => true] + $diagnostics
            : $successSchema;

        return [
            'description' => $this->agentOutputDescription($taskName, $agentOutput?->description),
            'success' => $successSchema,
            'compactSuccess' => $compactSuccessSchema,
            'error' => ['success' => false, 'error' => 'string'] + $diagnostics,
        ];
    }

    private function agentOutputDescription(string $taskName, ?string $fallback): ?string
    {
        if ($fallback === null) {
            return null;
        }

        return $this->compactFormatter->text('agent.output.' . $taskName, $fallback);
    }

    /**
     * @return array<string, string>|string
     */
    private function resolveOutputProperties(?AgentOutput $agentOutput): array|string
    {
        if ($agentOutput === null) {
            return '(undescribed)';
        }

        if ($agentOutput->properties !== []) {
            return $agentOutput->properties;
        }

        if ($agentOutput->responseClass !== null) {
            return $this->reflectResponseClass($agentOutput->responseClass);
        }

        return '(undescribed)';
    }

    /**
     * Reflect on a Response DTO class to build a property→type map.
     *
     * @param class-string $className
     * @return array<string, string>
     */
    private function reflectResponseClass(string $className): array
    {
        $rc = new \ReflectionClass($className);
        $properties = [];
        foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $type = $prop->getType();
            $typeName = 'mixed';
            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
                if ($type->allowsNull()) {
                    $typeName .= '|null';
                }
            }
            $properties[$prop->getName()] = $typeName;
        }

        return $properties;
    }

    /**
     * Overlay repository label defaults onto submit and items:create schema defaults.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $projectConfig
     * @return array<string, mixed>
     */
    public function applyProjectLabelDefaults(array $schema, array $projectConfig): array
    {
        $commands = $schema['commands'] ?? null;
        if (! is_array($commands)) {
            return $schema;
        }

        $workItemLabels = ProjectStudConfigKeys::readLabelList($projectConfig, ProjectStudConfigKeys::WORK_ITEM_LABELS);
        $pullRequestLabels = ProjectStudConfigKeys::readLabelList($projectConfig, ProjectStudConfigKeys::PULL_REQUEST_LABELS);

        foreach ($commands as $index => $command) {
            if (! is_array($command)) {
                continue;
            }
            $schema['commands'][$index] = $this->overlayCommandLabelDefault($command, $workItemLabels, $pullRequestLabels);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $command
     * @param list<string> $workItemLabels
     * @param list<string> $pullRequestLabels
     * @return array<string, mixed>
     */
    private function overlayCommandLabelDefault(array $command, array $workItemLabels, array $pullRequestLabels): array
    {
        $name = $command['name'] ?? '';
        if ($name === 'submit' && $pullRequestLabels !== []) {
            $command['input']['properties']['labels']['default'] = implode(',', $pullRequestLabels);
        }
        if ($name === 'items:create' && $workItemLabels !== []) {
            $command['input']['properties']['fields']['default'] = ['labels' => $workItemLabels];
            $command['input']['properties']['fields']['type'] = 'string|array|null';
        }

        return $command;
    }
}
