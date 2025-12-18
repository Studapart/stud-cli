<?php

declare(strict_types=1);

namespace App\Service;

class HelpService
{
    private const README_PATH = __DIR__ . '/../../README.md';

    // Map of command names to their README section patterns
    private const COMMAND_PATTERNS = [
        'config:init' => 'stud config:init',
        'completion' => 'stud completion',
        'projects:list' => 'stud projects:list',
        'items:list' => 'stud items:list',
        'items:search' => 'stud items:search',
        'items:show' => 'stud items:show',
        'items:start' => 'stud items:start',
        'filters:show' => 'stud filters:show',
        'commit' => 'stud commit',
        'please' => 'stud please',
        'flatten' => 'stud flatten',
        'cache:clear' => 'stud cache:clear',
        'status' => 'stud status',
        'submit' => 'stud submit',
        'pr:comment' => 'stud pr:comment',
        'update' => 'stud update',
        'release' => 'stud release',
        'deploy' => 'stud deploy',
    ];

    public function __construct(
        private readonly TranslationService $translator
    ) {
    }

    /**
     * Get help text for a command from README.md
     */
    public function getCommandHelp(string $commandName): ?string
    {
        if (! isset(self::COMMAND_PATTERNS[$commandName])) {
            return null;
        }

        $readmeContent = @file_get_contents(self::README_PATH);
        // File read failure is extremely rare and hard to simulate in tests
        // @codeCoverageIgnoreStart
        if ($readmeContent === false) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        $pattern = self::COMMAND_PATTERNS[$commandName];
        $lines = explode("\n", $readmeContent);

        $helpLines = [];
        $inSection = false;
        $foundCommand = false;

        foreach ($lines as $line) {
            // Look for the command section (starts with -   **`stud ...`)
            if (! $foundCommand && preg_match('/^-\s+\*\*`' . preg_quote($pattern, '/') . '/', $line)) {
                $foundCommand = true;
                $inSection = true;
                // Extract the command line itself
                $helpLines[] = $line;

                continue;
            }

            if ($inSection) {
                // Stop at next command (starts with -   **`stud) or section header (####)
                if (preg_match('/^#### |^-\s+\*\*`stud /', $line)) {
                    $currentIndent = strlen($line) - strlen(ltrim($line));
                    // If it's a new command at same or less indent, stop
                    if (str_contains($line, 'stud ') && $currentIndent <= 4) {
                        break;
                    }
                    // If it's a section header, stop
                    if (preg_match('/^#### /', $line)) {
                        break;
                    }
                }

                // Collect help text lines (skip empty lines at start)
                if (! empty($helpLines) || trim($line) !== '') {
                    $helpLines[] = $line;
                }
            }
        }

        if (empty($helpLines)) {
            return null;
        }

        // Clean up and format the help text
        $helpText = implode("\n", $helpLines);
        // Remove markdown formatting but keep structure
        $helpText = preg_replace('/\*\*`([^`]+)`\*\*/', '$1', $helpText); // Remove bold code markers
        $helpText = preg_replace('/`([^`]+)`/', '$1', $helpText); // Remove code markers
        $helpText = preg_replace('/\*\*([^*]+)\*\*/', '$1', $helpText); // Remove bold markers
        $helpText = preg_replace('/^-\s+/m', '', $helpText); // Remove list markers
        $helpText = preg_replace('/^    /m', '', $helpText); // Remove indentation
        $helpText = trim($helpText);

        return $helpText ?: null;
    }

    /**
     * Display help for a command
     */
    public function displayCommandHelp(\Symfony\Component\Console\Style\SymfonyStyle $io, string $commandName): void
    {
        // Always use translation-based help for consistent formatting with aliases and options
        $helpText = $this->formatCommandHelpFromTranslation($commandName);

        $io->section($this->translator->trans('help.command_help_title', ['command' => $commandName]));
        $io->writeln($helpText);
        $io->newLine();
        $io->note($this->translator->trans('help.see_readme_note'));
    }

    /**
     * Format command help from translation keys
     */
    protected function formatCommandHelpFromTranslation(string $commandName): string
    {
        // Map command names to their translation keys and metadata
        $commandMap = [
            'config:init' => [
                'alias' => 'init',
                'description_key' => 'help.command_config_init',
                'options' => [],
                'arguments' => [],
            ],
            'completion' => [
                'alias' => null,
                'description_key' => 'help.command_completion',
                'options' => [],
                'arguments' => ['<shell>'],
            ],
            'projects:list' => [
                'alias' => 'pj',
                'description_key' => 'help.command_projects_list',
                'options' => [],
                'arguments' => [],
            ],
            'items:list' => [
                'alias' => 'ls',
                'description_key' => 'help.command_items_list',
                'options' => [
                    ['name' => '--all', 'shortcut' => '-a', 'description_key' => 'help.option_all', 'argument' => null],
                    ['name' => '--project', 'shortcut' => '-p', 'description_key' => 'help.option_project', 'argument' => '<key>'],
                ],
                'arguments' => [],
            ],
            'items:search' => [
                'alias' => 'search',
                'description_key' => 'help.command_items_search',
                'options' => [],
                'arguments' => ['<jql>'],
            ],
            'filters:list' => [
                'alias' => 'fl',
                'description_key' => 'help.command_filters_list',
                'options' => [],
                'arguments' => [],
            ],
            'filters:show' => [
                'alias' => 'fs',
                'description_key' => 'help.command_filters_show',
                'options' => [],
                'arguments' => ['<filterName>'],
            ],
            'items:show' => [
                'alias' => 'sh',
                'description_key' => 'help.command_items_show',
                'options' => [],
                'arguments' => ['<key>'],
            ],
            'items:start' => [
                'alias' => 'start',
                'description_key' => 'help.command_items_start',
                'options' => [],
                'arguments' => ['<key>'],
            ],
            'items:takeover' => [
                'alias' => 'to',
                'description_key' => 'help.command_items_takeover',
                'options' => [],
                'arguments' => ['<key>'],
            ],
            'commit' => [
                'alias' => 'co',
                'description_key' => 'help.command_commit',
                'options' => [
                    ['name' => '--new', 'shortcut' => null, 'description_key' => 'help.option_commit_new', 'argument' => null],
                    ['name' => '--message', 'shortcut' => '-m', 'description_key' => 'help.option_commit_message', 'argument' => '<message>'],
                ],
                'arguments' => [],
            ],
            'please' => [
                'alias' => 'pl',
                'description_key' => 'help.command_please',
                'options' => [],
                'arguments' => [],
            ],
            'flatten' => [
                'alias' => 'ft',
                'description_key' => 'help.command_flatten',
                'options' => [],
                'arguments' => [],
            ],
            'cache:clear' => [
                'alias' => 'cc',
                'description_key' => 'help.command_cache_clear',
                'options' => [],
                'arguments' => [],
            ],
            'status' => [
                'alias' => 'ss',
                'description_key' => 'help.command_status',
                'options' => [],
                'arguments' => [],
            ],
            'submit' => [
                'alias' => 'su',
                'description_key' => 'help.command_submit',
                'options' => [
                    ['name' => '--draft', 'shortcut' => '-d', 'description_key' => 'help.option_submit_draft', 'argument' => null],
                    ['name' => '--labels', 'shortcut' => null, 'description_key' => 'help.option_submit_labels', 'argument' => '<labels>'],
                ],
                'arguments' => [],
            ],
            'pr:comment' => [
                'alias' => 'pc',
                'description_key' => 'help.command_pr_comment',
                'options' => [],
                'arguments' => ['<message>'],
            ],
            'update' => [
                'alias' => 'up',
                'description_key' => 'help.command_update',
                'options' => [
                    ['name' => '--info', 'shortcut' => '-i', 'description_key' => 'help.option_update_info', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'release' => [
                'alias' => 'rl',
                'description_key' => 'help.command_release',
                'options' => [
                    ['name' => '--major', 'shortcut' => '-M', 'description_key' => 'help.option_release_major', 'argument' => null],
                    ['name' => '--minor', 'shortcut' => '-m', 'description_key' => 'help.option_release_minor', 'argument' => null],
                    ['name' => '--patch', 'shortcut' => '-b', 'description_key' => 'help.option_release_patch', 'argument' => null],
                    ['name' => '--publish', 'shortcut' => '-p', 'description_key' => 'help.option_release_publish', 'argument' => null],
                ],
                'arguments' => ['[<version>]'],
            ],
            'deploy' => [
                'alias' => 'mep',
                'description_key' => 'help.command_deploy',
                'options' => [],
                'arguments' => [],
            ],
        ];

        if (! isset($commandMap[$commandName])) {
            return $this->translator->trans('help.command_not_found', ['command' => $commandName]);
        }

        $command = $commandMap[$commandName];
        $lines = [];

        // Command name with alias
        $commandLine = "stud {$commandName}";
        if (! empty($command['arguments'])) {
            $commandLine .= ' ' . implode(' ', $command['arguments']);
        }
        if ($command['alias']) {
            $commandLine .= " (Alias: stud {$command['alias']}";
            if (! empty($command['arguments'])) {
                $commandLine .= ' ' . implode(' ', $command['arguments']);
            }
            $commandLine .= ')';
        }
        $lines[] = $commandLine;

        // Description
        $description = $this->translator->trans($command['description_key']);
        $lines[] = "-   Description: {$description}";

        // Options
        if (! empty($command['options'])) {
            $lines[] = "-   Options:";
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
        }

        // Usage examples
        $lines[] = "-   Usage:";
        $lines[] = "    ``bash";

        // Build example values for arguments
        $exampleArgs = [];
        foreach ($command['arguments'] as $arg) {
            if ($arg === '<key>') {
                $exampleArgs[] = 'JIRA-33';
            } elseif (str_contains($arg, '<version>')) {
                $exampleArgs[] = '1.2.0';
            } elseif ($arg === '<jql>') {
                $exampleArgs[] = '"project = PROJ and status = Done"';
            } elseif ($arg === '<shell>') {
                $exampleArgs[] = 'bash';
            } elseif ($arg === '<message>') {
                $exampleArgs[] = '"Comment text"';
            } elseif ($arg === '<filterName>') {
                $exampleArgs[] = '"My Filter"';
            } else {
                // Fallback for unknown argument types
                // Currently all commands use known patterns, so this path is untestable
                // @codeCoverageIgnoreStart
                $exampleArgs[] = str_replace(['<', '>'], '', $arg);
                // @codeCoverageIgnoreEnd
            }
        }
        $argsString = ! empty($exampleArgs) ? ' ' . implode(' ', $exampleArgs) : '';

        // Basic usage with command name
        $lines[] = "    stud {$commandName}{$argsString}";

        // Basic usage with alias
        if ($command['alias']) {
            $lines[] = "    stud {$command['alias']}{$argsString}";
        }

        // Usage with options
        if (! empty($command['options'])) {
            // Show example with first option
            $firstOption = $command['options'][0];
            $optionExample = $firstOption['shortcut'] ?: $firstOption['name'];
            // Currently all commands have first option without arguments
            // This code path is untestable with current command set
            // @codeCoverageIgnoreStart
            if (isset($firstOption['argument']) && $firstOption['argument']) {
                $optionArg = '';
                if ($firstOption['argument'] === '<labels>') {
                    $optionArg = ' "bug,enhancement"';
                } elseif ($firstOption['argument'] === '<message>') {
                    $optionArg = ' "feat: My custom message"';
                } elseif ($firstOption['argument'] === '<key>') {
                    $optionArg = ' PROJ';
                }
                $optionExample .= $optionArg;
            }
            // @codeCoverageIgnoreEnd
            $lines[] = "    stud {$commandName}{$argsString} {$optionExample}";
            if ($command['alias']) {
                $lines[] = "    stud {$command['alias']}{$argsString} {$optionExample}";
            }

            // If there's a second option, show it too
            if (count($command['options']) > 1) {
                $secondOption = $command['options'][1];
                $secondOptionExample = $secondOption['shortcut'] ?: $secondOption['name'];
                if (isset($secondOption['argument']) && $secondOption['argument']) {
                    $optionArg = '';
                    if ($secondOption['argument'] === '<labels>') {
                        $optionArg = ' "bug,enhancement"';
                    } elseif ($secondOption['argument'] === '<message>') {
                        $optionArg = ' "feat: My custom message"';
                    } elseif ($secondOption['argument'] === '<key>') {
                        $optionArg = ' PROJ';
                    }
                    $secondOptionExample .= $optionArg;
                }
                $lines[] = "    stud {$commandName}{$argsString} {$secondOptionExample}";
                if ($command['alias']) {
                    $lines[] = "    stud {$command['alias']}{$argsString} {$secondOptionExample}";
                }
            }
        }
        $lines[] = "    ``";

        return implode("\n", $lines);
    }
}
