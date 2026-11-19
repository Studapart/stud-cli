<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Shapes the compact (non-expanded) agent-mode schema and resolves agent-domain translations.
 */
class AgentModeCompactSchemaFormatter
{
    public const COMPACT_PROPERTY = [
        'type' => 'bool',
        'optional' => true,
        'default' => true,
    ];

    public function __construct(
        private readonly ?TranslationService $translator = null
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function meta(): array
    {
        return [
            'description' => $this->text('agent.meta.description', 'Agent contract. Input is JSON via stdin/file; output is one JSON object.'),
            'generatedBy' => AgentModeSchemaGenerator::GENERATED_BY,
            'defaultDiscovery' => $this->text('agent.meta.defaultDiscovery', 'Default lists essential commands only.'),
            'fullDiscovery' => $this->text('agent.meta.fullDiscovery', 'Set essential=false to list every command.'),
            'commandDiscovery' => $this->text('agent.meta.commandDiscovery', 'Set command=name for expanded command help.'),
        ];
    }

    /**
     * @param array<string, mixed> $taskDef
     * @return array<string, mixed>
     */
    public function command(array $taskDef): array
    {
        unset($taskDef['parameters']);
        unset($taskDef['input']['properties']['compact']);
        $taskDef['description'] = $this->text('agent.command.' . $taskDef['name'], (string) $taskDef['description']);
        $taskDef['output'] = $this->outputSchema($taskDef['output']);

        return $taskDef;
    }

    /**
     * @return array{globalInput: array{compact: array<string, mixed>}, responseSchemas: array<string, mixed>}
     */
    public function globalSections(): array
    {
        return [
            'globalInput' => [
                'compact' => self::COMPACT_PROPERTY,
            ],
            'responseSchemas' => $this->responseSchemaDescriptors(),
        ];
    }

    public function text(string $key, string $fallback): string
    {
        if ($this->translator === null) {
            return $fallback;
        }

        $translated = $this->translator->trans($key, domain: 'agent', locale: 'en');

        return $translated === $key ? $fallback : $translated;
    }

    /**
     * @param array<string, mixed> $outputSchema
     * @return array<string, mixed>
     */
    protected function outputSchema(array $outputSchema): array
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
    protected function responseSchemaDescriptors(): array
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
            'note' => $this->text(
                'agent.response.note',
                'Use this schema for normal calls. Query command help only when data semantics are unclear. Compact success omits data only when no reusable data exists; diagnostics are included when present.'
            ),
        ];
    }
}
