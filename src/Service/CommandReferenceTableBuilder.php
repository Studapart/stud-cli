<?php

declare(strict_types=1);

namespace App\Service;

class CommandReferenceTableBuilder
{
    public function __construct(
        private readonly TranslationService $translator
    ) {
    }

    /**
     * @param array{arguments: array<int, string>}|null $meta
     *
     * @return list<string>
     */
    public function buildArguments(?array $meta): array
    {
        $arguments = $meta['arguments'] ?? [];
        if ($arguments === []) {
            return ['#### Arguments', '', 'None.', ''];
        }

        $lines = ['#### Arguments', '', '| Argument |', '| --- |'];
        foreach ($arguments as $argument) {
            $lines[] = '| `' . $this->escapeTable($argument) . '` |';
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param array{options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>}|null $meta
     * @param array<string, mixed> $command
     *
     * @return list<string>
     */
    public function buildOptions(?array $meta, array $command): array
    {
        $options = $meta['options'] ?? [];
        if ($options === []) {
            $schemaOptions = $command['parameters']['options'] ?? [];
            if (! is_array($schemaOptions) || $schemaOptions === []) {
                return ['#### Options', '', 'None.', ''];
            }
        }

        $lines = ['#### Options', '', '| Option | Description |', '| --- | --- |'];
        foreach ($options as $option) {
            $signature = $option['name'] . ($option['argument'] !== null ? ' ' . $option['argument'] : '');
            if ($option['shortcut'] !== null) {
                $signature .= '<br>`' . $option['shortcut'] . ($option['argument'] !== null ? ' ' . $option['argument'] : '') . '`';
            }
            $lines[] = '| `' . $this->escapeTable($signature) . '` | ' . $this->escapeTable($this->translator->trans($option['description_key'])) . ' |';
        }
        if ($options === []) {
            foreach ($command['parameters']['options'] as $option) {
                $lines[] = '| `' . $this->escapeTable((string) $option) . '` | See command schema. |';
            }
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param array<string, mixed> $command
     *
     * @return list<string>
     */
    public function buildAgentInput(array $command): array
    {
        $properties = $command['input']['properties'] ?? [];
        if (! is_array($properties) || $properties === []) {
            return ['#### Agent JSON Input', '', 'No input properties.', ''];
        }

        $lines = ['#### Agent JSON Input', '', '| Property | Type | Optional | Default |', '| --- | --- | --- | --- |'];
        foreach ($properties as $property => $schema) {
            $schema = is_array($schema) ? $schema : [];
            $lines[] = '| `' . $this->escapeTable((string) $property) . '` | `' . $this->escapeTable((string) ($schema['type'] ?? 'mixed')) . '` | ' . ($schema['optional'] ?? false ? 'yes' : 'no') . ' | `' . $this->escapeTable($this->defaultValue($schema)) . '` |';
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param array<string, mixed> $schema
     */
    protected function defaultValue(array $schema): string
    {
        if (! array_key_exists('default', $schema)) {
            return '';
        }

        if (is_scalar($schema['default']) || $schema['default'] === null) {
            return var_export($schema['default'], true);
        }

        $encoded = json_encode($schema['default'], JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    protected function escapeTable(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
