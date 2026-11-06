<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GlobalStudConfigFieldMap;
use App\Config\ProjectStudConfigFieldMap;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;

/**
 * Builds the agent-mode parameter labels and JSON input properties of one task
 * from its reflected function parameters.
 */
class AgentModeInputSchemaBuilder
{
    /** @var list<string> */
    public const COMMANDS_WITH_PROVIDER_OVERRIDE = [
        'items:create',
        'items:list',
        'items:show',
        'items:start',
        'items:transition',
        'items:update',
        'items:takeover',
        'items:download',
        'items:upload',
        'items:search',
        'filters:list',
        'filters:show',
        'projects:list',
        'projects:workflow',
        'projects:labels',
        'commit',
        'push',
        'submit',
        'status',
        'branch:rename',
    ];

    private const AGENT_ONLY_PARAMS = ['agent', 'inputFile'];

    private const PROVIDER_OVERRIDE_INPUT_SCHEMA = [
        'type' => 'string|null',
        'optional' => true,
        'default' => null,
        'enum' => ['jira', 'linear'],
    ];

    /**
     * Agent-only input properties not represented as function parameters, prepended per task.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const PREPENDED_INPUT_PROPERTIES = [
        'confluence:push' => [
            'file' => ['type' => 'string', 'optional' => true],
            'content' => ['type' => 'string', 'optional' => true, 'default' => ''],
        ],
        'push' => [
            'pleaseFallback' => ['type' => 'bool', 'optional' => true, 'default' => true],
        ],
        'submit' => [
            'stageAll' => ['type' => 'bool', 'optional' => true, 'default' => false],
            'isNew' => ['type' => 'bool', 'optional' => true, 'default' => false],
            'message' => ['type' => 'string|null', 'optional' => true, 'default' => null],
            'pleaseFallback' => ['type' => 'bool', 'optional' => true, 'default' => true],
        ],
        'items:download' => [
            'issueKey' => ['type' => 'string|null', 'optional' => true, 'default' => null],
        ],
        'items:upload' => [
            'issueKey' => ['type' => 'string|null', 'optional' => true, 'default' => null],
        ],
        'help' => [
            'commandName' => ['type' => 'string|null', 'optional' => true, 'default' => null],
            'command' => ['type' => 'string|null', 'optional' => true, 'default' => null],
            'essential' => ['type' => 'bool', 'optional' => true, 'default' => true],
        ],
    ];

    /**
     * @param \ReflectionParameter[] $params
     * @return array{options: list<string>, arguments: list<string>, properties: array<string, array<string, mixed>>}
     */
    public function build(string $taskName, array $params): array
    {
        $options = [];
        $arguments = [];
        $inputProperties = [];

        foreach ($params as $param) {
            $this->collectOption($param, $options, $inputProperties);
            $this->collectArgument($param, $arguments, $inputProperties);
        }

        return [
            'options' => $options,
            'arguments' => $arguments,
            'properties' => $this->injectExtraInputProperties($taskName, $inputProperties),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $inputProperties
     * @return array<string, array<string, mixed>>
     */
    private function injectExtraInputProperties(string $taskName, array $inputProperties): array
    {
        if ($taskName === 'config:init') {
            return $this->buildConfigInitInputProperties();
        }

        if ($taskName === 'config:project-init') {
            return $this->buildConfigProjectInitInputProperties();
        }

        if ($taskName === 'push') {
            unset($inputProperties['noPlease']);
        }

        $inputProperties = (self::PREPENDED_INPUT_PROPERTIES[$taskName] ?? []) + $inputProperties;

        if (in_array($taskName, self::COMMANDS_WITH_PROVIDER_OVERRIDE, true)) {
            $inputProperties['provider'] = self::PROVIDER_OVERRIDE_INPUT_SCHEMA;
        }

        return $inputProperties;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildConfigInitInputProperties(): array
    {
        $properties = [];
        foreach (array_keys(GlobalStudConfigFieldMap::INPUT_TO_YAML) as $key) {
            $properties[$key] = match ($key) {
                'gitProviders', 'issueTrackerProviders' => ['type' => 'array', 'optional' => true, 'default' => null],
                'jiraTransitionEnabled' => ['type' => 'bool', 'optional' => true, 'default' => null],
                default => ['type' => 'string|null', 'optional' => true, 'default' => null],
            };
        }

        return $properties;
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
                'workItemLabels', 'pullRequestLabels' => ['type' => 'array', 'optional' => true, 'default' => null],
                default => ['type' => 'string|null', 'optional' => true, 'default' => null],
            };
        }
        $properties['skipBaseBranchRemoteCheck'] = ['type' => 'bool', 'optional' => true, 'default' => false];

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
