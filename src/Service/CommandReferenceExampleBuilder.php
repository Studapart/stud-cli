<?php

declare(strict_types=1);

namespace App\Service;

class CommandReferenceExampleBuilder
{
    /** @var array<string, string> */
    private const ARGUMENT_SAMPLES = [
        '<key>' => 'SCI-123',
        '<jql>' => '"project = SCI and statusCategory != Done"',
        '<filterName>' => '"My Filter"',
        '<shell>' => 'bash',
        '<message>' => '"Ready for review"',
        '<version>' => '1.2.0',
        '<branch>' => 'feat/SCI-123-existing-branch',
        '<name>' => 'custom-branch-name',
        '<type>' => 'Task',
        '<text>' => '"Updated text"',
        '<plain|markdown>' => 'markdown',
        '<fields>' => '"labels=DX;priority=High"',
        '<path>' => 'doc.md',
        '<dir>' => '.cursor/tmp/SCI-123',
        '<url>' => '"https://example.atlassian.net/wiki/spaces/DEV/pages/12345/Page"',
        '<id>' => '12345',
        '<title>' => '"Feature documentation"',
        '<email>' => 'user@example.com',
        '<list>' => 'research,DX',
        '<labels>' => '"AI-Generated,RFR"',
        '<target>' => 'github:review_thread:THREAD_ID',
    ];

    /** @var array<string, string> */
    private const PROPERTY_SAMPLES = [
        'key' => 'SCI-123',
        'issueKey' => 'SCI-123',
        'commandName' => 'config:validate',
        'command' => 'config:validate',
        'jql' => 'project = SCI and statusCategory != Done',
        'filterName' => 'My Filter',
        'message' => 'Ready for review',
        'labels' => 'AI-Generated,RFR',
        'fields' => 'labels=DX;priority=High',
        'url' => 'https://example.atlassian.net/wiki/spaces/DEV/pages/12345/Page',
        'file' => 'doc.md',
        'content' => '# Feature documentation',
        'page' => '12345',
        'pageId' => '12345',
        'title' => 'Feature documentation',
        'space' => 'DEV',
        'projectKey' => 'SCI',
        'baseBranch' => 'develop',
    ];

    /**
     * @param array<string, mixed> $command
     * @param array{options: array<int, array{name: string, shortcut: ?string, argument: ?string}>, arguments: array<int, string>}|null $meta
     * @param list<string> $aliases
     *
     * @return list<string>
     */
    public function build(string $name, array $command, ?array $meta, array $aliases): array
    {
        $examples = [$this->exampleForCommand($name, $meta)];
        foreach ($aliases as $alias) {
            $examples[] = $this->exampleForCommand($alias, $meta);
        }
        foreach ($meta['options'] ?? [] as $option) {
            $examples[] = $this->exampleForCommand($name, $meta) . ' ' . $this->exampleForOption($option);
        }
        $examples[] = $this->agentExample($name, $command);

        return ['', '#### Examples', '', '```bash', ...array_values(array_unique($examples)), '```', ''];
    }

    /**
     * @param array{arguments: array<int, string>}|null $meta
     */
    protected function exampleForCommand(string $name, ?array $meta): string
    {
        $args = [];
        foreach ($meta['arguments'] ?? [] as $argument) {
            if (! str_starts_with($argument, '[')) {
                $args[] = $this->sampleForArgument($argument);
            }
        }

        return 'stud ' . $name . ($args === [] ? '' : ' ' . implode(' ', $args));
    }

    /**
     * @param array{name: string, argument: ?string} $option
     */
    protected function exampleForOption(array $option): string
    {
        if ($option['argument'] === null) {
            return $option['name'];
        }

        return $option['name'] . ' ' . $this->sampleForArgument($option['argument']);
    }

    /**
     * @param array<string, mixed> $command
     */
    protected function agentExample(string $name, array $command): string
    {
        $properties = $command['input']['properties'] ?? [];
        $payload = [];
        if (is_array($properties)) {
            foreach ($properties as $property => $schema) {
                $payload[$property] = $this->sampleForProperty((string) $property, is_array($schema) ? $schema : []);
            }
        }

        $encoded = $payload === [] ? '{}' : json_encode($payload, JSON_UNESCAPED_SLASHES);
        $json = is_string($encoded) ? $encoded : '{}';

        return "echo '" . $json . "' | stud " . $name . ' --agent';
    }

    protected function sampleForArgument(string $argument): string
    {
        $normalized = trim($argument, '[]');

        return self::ARGUMENT_SAMPLES[$normalized] ?? strtolower(trim($normalized, '<>'));
    }

    /**
     * @param array<string, mixed> $schema
     */
    protected function sampleForProperty(string $property, array $schema): mixed
    {
        $type = (string) ($schema['type'] ?? '');
        if ($type === 'bool') {
            return true;
        }
        if ($type === 'int' || str_starts_with($type, 'int')) {
            return 1;
        }
        if ($type === 'array') {
            return ['example'];
        }

        return self::PROPERTY_SAMPLES[$property] ?? 'example';
    }
}
