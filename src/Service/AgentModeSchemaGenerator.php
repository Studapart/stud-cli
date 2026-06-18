<?php

declare(strict_types=1);

namespace App\Service;

use App\Attribute\AgentCommand;
use App\Attribute\AgentOutput;
use App\Config\ProjectStudConfigFieldMap;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
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
    private const AGENT_ONLY_PARAMS = ['agent', 'inputFile'];
    private const COMPACT_PROPERTY = [
        'type' => 'bool',
        'optional' => true,
        'default' => true,
    ];

    public function __construct(private readonly ?TranslationService $translator = null)
    {
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

            if (! $expandedOutput) {
                unset($taskDef['parameters']);
                unset($taskDef['input']['properties']['compact']);
                $taskDef['description'] = $this->agentText('agent.command.' . $taskDef['name'], (string) $taskDef['description']);
                $taskDef['output'] = $this->compactOutputSchema($taskDef['output']);
            }
            $commands[] = $taskDef;
        }

        usort($commands, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $schema = [
            'meta' => [
                'description' => $expandedOutput
                    ? 'Agent-mode schema — auto-generated from command signatures. When --agent is passed, input is a single JSON document (stdin or one positional file path) and output is a single JSON document.'
                    : $this->agentText('agent.meta.description', 'Agent contract. Input is JSON via stdin/file; output is one JSON object.'),
                'generatedBy' => 'AgentModeSchemaGenerator (runtime reflection)',
                'defaultDiscovery' => $expandedOutput
                    ? 'help --agent with empty input returns essential command schemas only.'
                    : $this->agentText('agent.meta.defaultDiscovery', 'Default lists essential commands only.'),
                'fullDiscovery' => $expandedOutput
                    ? 'Use {"essential": false} with help --agent to return every command schema.'
                    : $this->agentText('agent.meta.fullDiscovery', 'Set essential=false to list every command.'),
                'commandDiscovery' => $expandedOutput
                    ? 'Use {"command":"<name-or-alias>"} with help --agent for expanded command response data.'
                    : $this->agentText('agent.meta.commandDiscovery', 'Set command=name for expanded command help.'),
            ],
            'commands' => $commands,
        ];

        if (! $expandedOutput) {
            $schema['globalInput'] = [
                'compact' => self::COMPACT_PROPERTY,
            ];
            $schema['responseSchemas'] = $this->responseSchemaDescriptors();
        }

        return $schema;
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
        $options = [];
        $arguments = [];
        $inputProperties = [];

        foreach ($params as $param) {
            $this->collectOption($param, $options, $inputProperties);
            $this->collectArgument($param, $arguments, $inputProperties);
        }

        $this->injectExtraInputProperties($task->name, $inputProperties);
        $inputProperties = ['compact' => self::COMPACT_PROPERTY] + $inputProperties;

        $outputSchema = $this->buildOutputSchema($task->name, $agentOutput);

        return [
            'name' => $task->name,
            'aliases' => $task->aliases,
            'essential' => $agentCommand instanceof AgentCommand && $agentCommand->essential,
            'description' => $task->description,
            'parameters' => ['options' => $options, 'arguments' => $arguments],
            'input' => ['properties' => $inputProperties],
            'output' => $outputSchema,
        ];
    }

    /**
     * Inject agent-only input properties not represented as function parameters (e.g. confluence:push "content").
     *
     * @param array<string, array<string, mixed>> $inputProperties
     */
    private function injectExtraInputProperties(string $taskName, array &$inputProperties): void
    {
        if ($taskName === 'confluence:push') {
            $inputProperties = [
                'file' => ['type' => 'string', 'optional' => true],
                'content' => ['type' => 'string', 'optional' => true, 'default' => ''],
            ] + $inputProperties;
        }

        if ($taskName === 'push') {
            unset($inputProperties['noPlease']);
            $inputProperties = [
                'pleaseFallback' => ['type' => 'bool', 'optional' => true, 'default' => true],
            ] + $inputProperties;
        }

        if ($taskName === 'submit') {
            $inputProperties = [
                'stageAll' => ['type' => 'bool', 'optional' => true, 'default' => false],
                'isNew' => ['type' => 'bool', 'optional' => true, 'default' => false],
                'message' => ['type' => 'string|null', 'optional' => true, 'default' => null],
                'pleaseFallback' => ['type' => 'bool', 'optional' => true, 'default' => true],
            ] + $inputProperties;
        }

        if ($taskName === 'items:download') {
            $inputProperties = [
                'issueKey' => ['type' => 'string|null', 'optional' => true, 'default' => null],
            ] + $inputProperties;
        }

        if ($taskName === 'items:upload') {
            $inputProperties = [
                'issueKey' => ['type' => 'string|null', 'optional' => true, 'default' => null],
            ] + $inputProperties;
        }

        if ($taskName === 'help') {
            $inputProperties = [
                'commandName' => ['type' => 'string|null', 'optional' => true, 'default' => null],
                'command' => ['type' => 'string|null', 'optional' => true, 'default' => null],
                'essential' => ['type' => 'bool', 'optional' => true, 'default' => true],
            ] + $inputProperties;
        }

        if ($taskName === 'config:project-init') {
            $inputProperties = $this->buildConfigProjectInitInputProperties();
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildConfigProjectInitInputProperties(): array
    {
        $properties = [];
        foreach (array_keys(ProjectStudConfigFieldMap::INPUT_TO_YAML) as $key) {
            $properties[$key] = match ($key) {
                'transitionId' => ['type' => 'int|null', 'optional' => true, 'default' => null],
                'linearTypeBranchPrefixes' => ['type' => 'object', 'optional' => true, 'default' => null],
                default => ['type' => 'string|null', 'optional' => true, 'default' => null],
            };
        }
        $properties['skipBaseBranchRemoteCheck'] = ['type' => 'bool', 'optional' => true, 'default' => false];

        return $properties;
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

    /**
     * @param array<string, mixed> $outputSchema
     * @return array<string, mixed>
     */
    private function compactOutputSchema(array $outputSchema): array
    {
        $success = $outputSchema['success'] ?? [];
        $compactSuccess = $outputSchema['compactSuccess'] ?? $success;
        $successData = is_array($success) ? ($success['data'] ?? null) : null;
        $compactHasData = is_array($compactSuccess) && array_key_exists('data', $compactSuccess);

        $output = [
            'description' => $outputSchema['description'] ?? null,
            'compact' => [$compactHasData ? 'successData' : 'successOnly', 'error'],
            'full' => ['successData', 'error'],
        ];

        if ($successData !== null) {
            $output['data'] = $successData;
        }

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchemaDescriptors(): array
    {
        return [
            'successOnly' => ['success' => true],
            'successData' => ['success' => true, 'data' => 'command.output.data'],
            'error' => ['success' => false, 'error' => 'string', 'diagnostics?' => 'diagnostics'],
            'diagnostics' => [
                'errors?' => 'list<message>',
                'warnings?' => 'list<message>',
                'notices?' => 'list<message>',
                'info?' => 'list<message>',
            ],
            'message' => ['message' => 'string', 'technicalDetails?' => 'string', 'context?' => 'object'],
            'note' => $this->agentText(
                'agent.response.note',
                'Use this schema for normal calls. Query command help only when data semantics are unclear. Compact success omits data only when no reusable data exists; diagnostics are included when present.'
            ),
        ];
    }

    private function agentText(string $key, string $fallback): string
    {
        if ($this->translator === null) {
            return $fallback;
        }

        $translated = $this->translator->trans($key, domain: 'agent', locale: 'en');

        return $translated === $key ? $fallback : $translated;
    }

    private function agentOutputDescription(string $taskName, ?string $fallback): ?string
    {
        if ($fallback === null) {
            return null;
        }

        return $this->agentText('agent.output.' . $taskName, $fallback);
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
     * @param list<string> $options
     * @param array<string, array<string, mixed>> $inputProperties
     */
    private function collectOption(\ReflectionParameter $param, array &$options, array &$inputProperties): void
    {
        foreach ($param->getAttributes(AsOption::class) as $attr) {
            /** @var AsOption $opt */
            $opt = $attr->newInstance();
            $name = $opt->name ?? $param->getName();

            if (in_array($name, self::AGENT_ONLY_PARAMS, true)) {
                continue;
            }

            $shortcut = $opt->shortcut;
            $label = is_string($shortcut) && $shortcut !== '' ? "{$name} ({$shortcut})" : $name;
            $options[] = $label;

            $inputProperties[$param->getName()] = $this->buildPropertySchema($param);
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, array<string, mixed>> $inputProperties
     */
    private function collectArgument(\ReflectionParameter $param, array &$arguments, array &$inputProperties): void
    {
        foreach ($param->getAttributes(AsArgument::class) as $attr) {
            /** @var AsArgument $arg */
            $arg = $attr->newInstance();
            $name = $arg->name ?? $param->getName();

            if (in_array($name, self::AGENT_ONLY_PARAMS, true)) {
                continue;
            }

            $label = $param->isOptional() ? "{$name} (optional)" : $name;
            $arguments[] = $label;

            $inputProperties[$param->getName()] = $this->buildPropertySchema($param);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPropertySchema(\ReflectionParameter $param): array
    {
        $type = $param->getType();
        $typeName = 'mixed';
        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();
            if ($type->allowsNull() && $typeName !== 'mixed') {
                $typeName .= '|null';
            }
        }

        $schema = ['type' => $typeName];
        if ($param->isOptional()) {
            $schema['optional'] = true;
            if ($param->isDefaultValueAvailable()) {
                $schema['default'] = $param->getDefaultValue();
            }
        }

        return $schema;
    }
}
