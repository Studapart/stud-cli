<?php

declare(strict_types=1);

namespace App\Service;

use App\Attribute\AgentOutput;
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
final class AgentModeSchemaGenerator
{
    private const AGENT_ONLY_PARAMS = ['agent', 'inputFile'];

    /**
     * @param list<string>|null $functionNames Override function list (for testing); null = all user-defined functions.
     * @return array{meta: array<string, string>, commands: list<array<string, mixed>>}
     */
    public function generate(?array $functionNames = null): array
    {
        $commands = [];
        foreach ($this->discoverTasks($functionNames) as $taskDef) {
            $commands[] = $taskDef;
        }

        usort($commands, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return [
            'meta' => [
                'description' => 'Agent-mode schema — auto-generated from command signatures. When --agent is passed, input is a single JSON document (stdin or one positional file path) and output is a single JSON document.',
                'generatedBy' => 'AgentModeSchemaGenerator (runtime reflection)',
            ],
            'commands' => $commands,
        ];
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
            yield $this->buildCommandEntry($task, $rf->getParameters(), $agentOutput);
        }
    }

    /**
     * @param \ReflectionParameter[] $params
     * @return array<string, mixed>
     */
    private function buildCommandEntry(AsTask $task, array $params, ?AgentOutput $agentOutput): array
    {
        $options = [];
        $arguments = [];
        $inputProperties = [];

        foreach ($params as $param) {
            $this->collectOption($param, $options, $inputProperties);
            $this->collectArgument($param, $arguments, $inputProperties);
        }

        $outputSchema = $this->buildOutputSchema($agentOutput);

        return [
            'name' => $task->name,
            'aliases' => $task->aliases,
            'description' => $task->description,
            'parameters' => ['options' => $options, 'arguments' => $arguments],
            'input' => ['properties' => $inputProperties],
            'output' => $outputSchema,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOutputSchema(?AgentOutput $agentOutput): array
    {
        $dataProperties = $this->resolveOutputProperties($agentOutput);

        return [
            'description' => $agentOutput?->description,
            'success' => ['success' => true, 'data' => $dataProperties],
            'error' => ['success' => false, 'error' => 'string'],
        ];
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
