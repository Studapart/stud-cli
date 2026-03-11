<?php

declare(strict_types=1);

namespace App\Service;

class HelpService
{
    private const README_PATH = __DIR__ . '/../../README.md';

    // Map of command names to their README section patterns
    private const COMMAND_PATTERNS = [
        'config:init' => 'stud config:init',
        'config:show' => 'stud config:show',
        'config:validate' => 'stud config:validate',
        'completion' => 'stud completion',
        'projects:list' => 'stud projects:list',
        'items:list' => 'stud items:list',
        'items:search' => 'stud items:search',
        'items:show' => 'stud items:show',
        'items:create' => 'stud items:create',
        'items:start' => 'stud items:start',
        'items:transition' => 'stud items:transition',
        'filters:list' => 'stud filters:list',
        'filters:show' => 'stud filters:show',
        'branch:rename' => 'stud branch:rename',
        'commit' => 'stud commit',
        'commit:undo' => 'stud commit:undo',
        'please' => 'stud please',
        'flatten' => 'stud flatten',
        'sync' => 'stud sync',
        'cache:clear' => 'stud cache:clear',
        'status' => 'stud status',
        'submit' => 'stud submit',
        'pr:comment' => 'stud pr:comment',
        'pr:comments' => 'stud pr:comments',
        'update' => 'stud update',
        'release' => 'stud release',
        'deploy' => 'stud deploy',
        'branches:list' => 'stud branches:list',
        'branches:clean' => 'stud branches:clean',
    ];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly FileSystem $fileSystem
    ) {
    }

    /**
     * Resolves an absolute path to a relative path if it's within the current working directory.
     * This is needed because FileSystem::createLocal() uses getcwd() as the root.
     *
     * @param string $path The path to resolve
     * @return string The resolved path (relative to cwd if absolute, or original if already relative)
     */
    /**
     * @codeCoverageIgnore
     * Tested indirectly through getCommandHelp()
     */
    private function resolvePath(string $path): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return $path;
        }

        // If path is absolute and starts with cwd, make it relative
        if (str_starts_with($path, '/') && str_starts_with($path, $cwd)) {
            $relative = ltrim(str_replace($cwd, '', $path), '/');

            return $relative !== '' ? $relative : '.';
        }

        return $path;
    }

    /**
     * Get help text for a command from README.md
     */
    public function getCommandHelp(string $commandName): ?string
    {
        if (! isset(self::COMMAND_PATTERNS[$commandName])) {
            return null;
        }

        // Resolve absolute paths to relative paths if they're within the current working directory
        $readmePath = $this->resolvePath(self::README_PATH);

        try {
            $readmeContent = $this->fileSystem->read($readmePath);
        } catch (\RuntimeException $e) {
            // File read failure is extremely rare and hard to simulate in tests
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        $pattern = self::COMMAND_PATTERNS[$commandName];
        $helpLines = $this->extractHelpLinesFromReadme($readmeContent, $pattern);
        if (empty($helpLines)) {
            return null;
        }

        $helpText = $this->formatHelpText(implode("\n", $helpLines));
        if ($helpText === '') {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        return $helpText;
    }

    /**
     * Extract help section lines from README content for a given command pattern.
     *
     * @return array<int, string>
     */
    protected function extractHelpLinesFromReadme(string $readmeContent, string $pattern): array
    {
        $lines = explode("\n", $readmeContent);
        $helpLines = [];
        $inSection = false;
        $foundCommand = false;

        foreach ($lines as $line) {
            if (! $foundCommand && preg_match('/^-\s+\*\*`' . preg_quote($pattern, '/') . '/', $line)) {
                $foundCommand = true;
                $inSection = true;
                $helpLines[] = $line;

                continue;
            }

            if (! $inSection) {
                continue;
            }
            if ($this->shouldBreakHelpSection($line)) {
                break;
            }
            if (! empty($helpLines) || trim($line) !== '') {
                $helpLines[] = $line;
            }
        }

        return $helpLines;
    }

    protected function shouldBreakHelpSection(string $line): bool
    {
        if (! preg_match('/^#### |^-\s+\*\*`stud /', $line)) {
            return false;
        }
        $currentIndent = strlen($line) - strlen(ltrim($line));
        if (str_contains($line, 'stud ') && $currentIndent <= 4) {
            return true;
        }

        return preg_match('/^#### /', $line) === 1;
    }

    /**
     * Strip markdown formatting from help text and trim.
     */
    protected function formatHelpText(string $rawHelpText): string
    {
        $helpText = preg_replace('/\*\*`([^`]+)`\*\*/', '$1', $rawHelpText);
        $helpText = preg_replace('/`([^`]+)`/', '$1', $helpText);
        $helpText = preg_replace('/\*\*([^*]+)\*\*/', '$1', $helpText);
        $helpText = preg_replace('/^-\s+/m', '', $helpText);
        $helpText = preg_replace('/^    /m', '', $helpText);

        return trim($helpText);
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
        $commandMap = $this->getCommandMap();

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
     * Command names to translation keys and metadata (description, options, arguments).
     *
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    protected function getCommandMap(): array
    {
        return [
            'config:init' => [
                'alias' => 'init',
                'description_key' => 'help.command_config_init',
                'options' => [],
                'arguments' => [],
            ],
            'config:show' => [
                'alias' => null,
                'description_key' => 'help.command_config_show',
                'options' => [
                    ['name' => '--key', 'shortcut' => '-k', 'description_key' => 'help.option_config_show_key', 'argument' => '<key>'],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_config_show_quiet', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'config:validate' => [
                'alias' => null,
                'description_key' => 'help.command_config_validate',
                'options' => [
                    ['name' => '--skip-jira', 'shortcut' => null, 'description_key' => 'help.option_config_validate_skip_jira', 'argument' => null],
                    ['name' => '--skip-git', 'shortcut' => null, 'description_key' => 'help.option_config_validate_skip_git', 'argument' => null],
                ],
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
                    ['name' => '--sort', 'shortcut' => '-s', 'description_key' => 'help.option_items_list_sort', 'argument' => '<value>'],
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
            'items:create' => [
                'alias' => 'ic',
                'description_key' => 'help.command_items_create',
                'options' => [
                    ['name' => '--project', 'shortcut' => '-p', 'description_key' => 'help.option_items_create_project', 'argument' => '<key>'],
                    ['name' => '--type', 'shortcut' => '-t', 'description_key' => 'help.option_items_create_type', 'argument' => '<type>'],
                    ['name' => '--summary', 'shortcut' => '-m', 'description_key' => 'help.option_items_create_summary', 'argument' => '<text>'],
                    ['name' => '--description', 'shortcut' => '-d', 'description_key' => 'help.option_items_create_description', 'argument' => '<text>'],
                    ['name' => '--description-format', 'shortcut' => null, 'description_key' => 'help.option_items_create_description_format', 'argument' => '<plain|markdown>'],
                    ['name' => '--parent', 'shortcut' => null, 'description_key' => 'help.option_items_create_parent', 'argument' => '<key>'],
                    ['name' => '--assignee', 'shortcut' => null, 'description_key' => 'help.option_items_create_assignee', 'argument' => '<id>'],
                    ['name' => '--labels', 'shortcut' => null, 'description_key' => 'help.option_items_create_labels', 'argument' => '<list>'],
                    ['name' => '--original-estimate', 'shortcut' => null, 'description_key' => 'help.option_items_create_original_estimate', 'argument' => '<value>'],
                ],
                'arguments' => [],
            ],
            'items:transition' => [
                'alias' => 'tx',
                'description_key' => 'help.command_items_transition',
                'options' => [],
                'arguments' => ['[<key>]'],
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
                'options' => [
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => ['<key>'],
            ],
            'branch:rename' => [
                'alias' => 'rn',
                'description_key' => 'help.command_branch_rename',
                'options' => [
                    ['name' => '--name', 'shortcut' => '-n', 'description_key' => 'help.option_branch_rename_name', 'argument' => '<name>'],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => ['[<branch>]', '[<key>]'],
            ],
            'commit' => [
                'alias' => 'co',
                'description_key' => 'help.command_commit',
                'options' => [
                    ['name' => '--new', 'shortcut' => null, 'description_key' => 'help.option_commit_new', 'argument' => null],
                    ['name' => '--message', 'shortcut' => '-m', 'description_key' => 'help.option_commit_message', 'argument' => '<message>'],
                    ['name' => '--all', 'shortcut' => '-a', 'description_key' => 'help.option_commit_all', 'argument' => null],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'commit:undo' => [
                'alias' => 'undo',
                'description_key' => 'help.command_commit_undo',
                'options' => [
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
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
            'sync' => [
                'alias' => 'sy',
                'description_key' => 'sync.help_description',
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
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'pr:comment' => [
                'alias' => 'pc',
                'description_key' => 'help.command_pr_comment',
                'options' => [],
                'arguments' => ['<message>'],
            ],
            'pr:comments' => [
                'alias' => 'pcs',
                'description_key' => 'help.command_pr_comments',
                'options' => [],
                'arguments' => [],
            ],
            'update' => [
                'alias' => 'up',
                'description_key' => 'help.command_update',
                'options' => [
                    ['name' => '--info', 'shortcut' => '-i', 'description_key' => 'help.option_update_info', 'argument' => null],
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
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
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_quiet', 'argument' => null],
                ],
                'arguments' => ['[<version>]'],
            ],
            'deploy' => [
                'alias' => 'mep',
                'description_key' => 'help.command_deploy',
                'options' => [
                    ['name' => '--clean', 'shortcut' => null, 'description_key' => 'help.option_deploy_clean', 'argument' => null],
                ],
                'arguments' => [],
            ],
            'branches:list' => [
                'alias' => 'bl',
                'description_key' => 'help.command_branches_list',
                'options' => [],
                'arguments' => [],
            ],
            'branches:clean' => [
                'alias' => 'bc',
                'description_key' => 'help.command_branches_clean',
                'options' => [
                    ['name' => '--quiet', 'shortcut' => '-q', 'description_key' => 'help.option_branches_clean_quiet', 'argument' => null],
                ],
                'arguments' => [],
            ],
        ];
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
