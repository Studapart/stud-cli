<?php

declare(strict_types=1);

namespace App\Service;

class CommandHelpFormatter
{
    /** @var array<string, string> */
    private const ARGUMENT_EXAMPLE_MAP = [
        '<key>' => 'JIRA-33',
        '<jql>' => '"project = PROJ and status = Done"',
        '<shell>' => 'bash',
        '<message>' => '"Comment text"',
        '<filterName>' => '"My Filter"',
        '<version>' => '1.2.0',
        '<branch>' => 'feat/OLD-123-old',
    ];

    /** @var array<string, string> */
    private const OPTION_ARGUMENT_SUFFIX_MAP = [
        '<labels>' => ' "bug,enhancement"',
        '<message>' => ' "feat: My custom message"',
        '<key>' => ' PROJ',
        '<value>' => ' Key',
        '<name>' => ' custom-branch-name',
        '<type>' => ' Story',
        '<text>' => ' "Summary or description text"',
    ];

    public function __construct(
        private readonly TranslationService $translator
    ) {
    }

    /**
     * Format command help from translation keys
     */
    public function format(string $commandName): string
    {
        $commandMap = CommandMap::all();

        if (! isset($commandMap[$commandName])) {
            return $this->translator->trans('help.command_not_found', ['command' => $commandName]);
        }

        $command = $commandMap[$commandName];
        $lines = [];

        $lines[] = $this->buildCommandLineWithAlias($command, $commandName);
        $lines[] = '-   Description: ' . $this->translator->trans($command['description_key']);
        $lines = array_merge($lines, $this->buildOptionsLines($command));
        $exampleArgs = $this->buildExampleArgs($command['arguments']);
        $argsString = ! empty($exampleArgs) ? ' ' . implode(' ', $exampleArgs) : '';
        $lines = array_merge($lines, $this->buildUsageSectionLines($command, $commandName, $argsString));

        return implode("\n", $lines);
    }

    /**
     * Build the command line string including alias when present.
     *
     * @param array{alias: ?string, arguments: array<int, string>} $command
     */
    protected function buildCommandLineWithAlias(array $command, string $commandName): string
    {
        $commandLine = "stud {$commandName}";
        if (! empty($command['arguments'])) {
            $commandLine .= ' ' . implode(' ', $command['arguments']);
        }
        if ($command['alias'] !== null && $command['alias'] !== '') {
            $commandLine .= " (Alias: stud {$command['alias']}";
            if (! empty($command['arguments'])) {
                $commandLine .= ' ' . implode(' ', $command['arguments']);
            }
            $commandLine .= ')';
        }

        return $commandLine;
    }

    /**
     * Build options section lines.
     *
     * @param array{options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>} $command
     *
     * @return array<int, string>
     */
    protected function buildOptionsLines(array $command): array
    {
        $lines = [];
        if (empty($command['options'])) {
            return $lines;
        }
        $lines[] = '-   Options:';
        foreach ($command['options'] as $option) {
            $optionName = $option['name'];
            if (isset($option['argument']) && $option['argument']) {
                $optionName .= ' ' . $option['argument'];
            }
            if (isset($option['shortcut']) && $option['shortcut']) {
                $shortcutName = $option['shortcut'];
                if (isset($option['argument']) && $option['argument']) {
                    $shortcutName .= ' ' . $option['argument'];
                }
                $optionName .= " or {$shortcutName}";
            }
            $optionDesc = $this->translator->trans($option['description_key']);
            $lines[] = "    -   {$optionName}: {$optionDesc}.";
        }

        return $lines;
    }

    /**
     * Build example argument values for usage section (required arguments only).
     *
     * @param array<int, string> $arguments
     *
     * @return array<int, string>
     */
    protected function buildExampleArgs(array $arguments): array
    {
        $exampleArgs = [];
        foreach ($arguments as $arg) {
            if (str_starts_with($arg, '[') && str_ends_with($arg, ']')) {
                continue;
            }
            $exampleArgs[] = self::ARGUMENT_EXAMPLE_MAP[$arg] ?? str_replace(['<', '>'], '', $arg);
        }

        return $exampleArgs;
    }

    /**
     * Build usage section lines (header, bash block, command examples).
     *
     * @param array{alias: ?string, options: array<int, array{name: string, shortcut: ?string, argument: ?string}>} $command
     *
     * @return array<int, string>
     */
    protected function buildUsageSectionLines(array $command, string $commandName, string $argsString): array
    {
        $lines = ['-   Usage:', '    ``bash', "    stud {$commandName}{$argsString}"];
        if ($command['alias'] !== null && $command['alias'] !== '') {
            $lines[] = "    stud {$command['alias']}{$argsString}";
        }
        if (! empty($command['options'])) {
            $firstOption = $command['options'][0];
            $optionExample = $this->buildOptionExample($firstOption);
            $lines[] = "    stud {$commandName}{$argsString} {$optionExample}";
            if ($command['alias'] !== null && $command['alias'] !== '') {
                $lines[] = "    stud {$command['alias']}{$argsString} {$optionExample}";
            }
            if (count($command['options']) > 1) {
                $secondOption = $command['options'][1];
                $secondOptionExample = $this->buildOptionExample($secondOption);
                $lines[] = "    stud {$commandName}{$argsString} {$secondOptionExample}";
                if ($command['alias'] !== null && $command['alias'] !== '') {
                    $lines[] = "    stud {$command['alias']}{$argsString} {$secondOptionExample}";
                }
            }
        }
        $lines[] = '    ``';

        return $lines;
    }

    /**
     * Build option example string (shortcut or name + argument suffix if present).
     *
     * @param array{name: string, shortcut: ?string, argument: ?string} $option
     */
    protected function buildOptionExample(array $option): string
    {
        $optionExample = $option['shortcut'] ?: $option['name'];
        if (! isset($option['argument']) || ! $option['argument']) {
            return $optionExample;
        }
        $suffix = self::OPTION_ARGUMENT_SUFFIX_MAP[$option['argument']] ?? '';

        return $optionExample . $suffix;
    }
}
