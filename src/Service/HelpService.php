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
        'config:project-init' => 'stud config:project-init',
        'completion' => 'stud completion',
        'projects:list' => 'stud projects:list',
        'projects:workflow' => 'stud projects:workflow',
        'projects:labels' => 'stud projects:labels',
        'items:list' => 'stud items:list',
        'items:search' => 'stud items:search',
        'items:show' => 'stud items:show',
        'items:create' => 'stud items:create',
        'items:update' => 'stud items:update',
        'items:start' => 'stud items:start',
        'items:transition' => 'stud items:transition',
        'filters:list' => 'stud filters:list',
        'filters:show' => 'stud filters:show',
        'branch:rename' => 'stud branch:rename',
        'commit' => 'stud commit',
        'commit:undo' => 'stud commit:undo',
        'push' => 'stud push',
        'please' => 'stud please',
        'flatten' => 'stud flatten',
        'sync' => 'stud sync',
        'switch' => 'stud switch',
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
        'confluence:push' => 'stud confluence:push',
        'confluence:show' => 'stud confluence:show',
        'confluence:page-labels' => 'stud confluence:page-labels',
    ];

    /** @var array<string, string> */
    private const GENERAL_HELP_EXAMPLE_MAP = [
        'completion' => 'stud completion bash',
        'items:list' => 'stud ls -p PROJ -s Key',
        'items:show' => 'stud sh PROJ-123',
        'items:create' => 'stud ic -p PROJ -m "Summary"',
        'items:search' => 'stud search "project = PROJ and status = Done"',
        'items:transition' => 'stud tx PROJ-123',
        'filters:show' => 'stud fs "My Filter"',
        'items:start' => 'stud start PROJ-123',
        'items:takeover' => 'stud to PROJ-123',
        'branch:rename' => 'stud rn feat/OLD-123-old ACME-4067',
        'release' => 'stud release 1.2.0',
        'deploy' => 'stud deploy',
    ];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly FileSystem $fileSystem,
        private readonly CommandHelpFormatter $commandHelpFormatter
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
    public function displayCommandHelp(Logger $logger, string $commandName): void
    {
        // Always use translation-based help for consistent formatting with aliases and options
        $helpText = $this->commandHelpFormatter->format($commandName);

        $logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('help.command_help_title', ['command' => $commandName]));
        $logger->writeln(Logger::VERBOSITY_NORMAL, $helpText);
        $logger->newLine(Logger::VERBOSITY_NORMAL);
        $logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('help.see_readme_note'));
    }

    /**
     * Build the grouped command rows used by the general `stud help` command.
     *
     * @return array<string, list<array{name: string, alias: ?string, args: string, description: string, example: ?string}>>
     */
    public function getGeneralHelpCommandGroups(): array
    {
        $groups = $this->emptyGeneralHelpGroups();

        foreach ($this->getCommandMap() as $name => $command) {
            $groups[$this->generalHelpCategoryFor($name)][] = $this->buildGeneralHelpCommandRow($name, $command);
        }

        return array_filter($groups);
    }

    /**
     * Command names to translation keys and metadata (description, options, arguments).
     *
     * @return array<string, array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>}>
     */
    protected function getCommandMap(): array
    {
        return CommandMap::all();
    }

    /**
     * @return array<string, list<array{name: string, alias: ?string, args: string, description: string, example: ?string}>>
     */
    protected function emptyGeneralHelpGroups(): array
    {
        return [
            $this->translator->trans('help.category_configuration') => [],
            $this->translator->trans('help.category_jira_information') => [],
            $this->translator->trans('help.category_confluence') => [],
            $this->translator->trans('help.category_git_workflow') => [],
            $this->translator->trans('help.category_release_commands') => [],
        ];
    }

    protected function generalHelpCategoryFor(string $name): string
    {
        if ($this->isConfigurationHelpCommand($name)) {
            return $this->translator->trans('help.category_configuration');
        }
        if ($this->isGitWorkflowHelpCommand($name)) {
            return $this->translator->trans('help.category_git_workflow');
        }
        if (str_starts_with($name, 'confluence:')) {
            return $this->translator->trans('help.category_confluence');
        }
        if ($this->isReleaseHelpCommand($name)) {
            return $this->translator->trans('help.category_release_commands');
        }

        return $this->translator->trans('help.category_jira_information');
    }

    protected function isConfigurationHelpCommand(string $name): bool
    {
        return str_starts_with($name, 'config:') || in_array($name, ['completion', 'docs:generate', 'docs:check'], true);
    }

    protected function isGitWorkflowHelpCommand(string $name): bool
    {
        return str_starts_with($name, 'branch:')
            || str_starts_with($name, 'branches:')
            || in_array($name, [
                'items:start',
                'items:takeover',
                'commit',
                'commit:undo',
                'push',
                'please',
                'flatten',
                'submit',
                'pr:comment',
                'pr:comments',
                'sync',
                'switch',
                'status',
            ], true);
    }

    protected function isReleaseHelpCommand(string $name): bool
    {
        return in_array($name, ['release', 'deploy', 'update', 'cache:clear', 'help'], true);
    }

    /**
     * @param array{alias: ?string, description_key: string, options: array<int, array{name: string, shortcut: ?string, description_key: string, argument: ?string}>, arguments: array<int, string>} $command
     *
     * @return array{name: string, alias: ?string, args: string, description: string, example: ?string}
     */
    protected function buildGeneralHelpCommandRow(string $name, array $command): array
    {
        return [
            'name' => $name,
            'alias' => $command['alias'],
            'args' => implode(' ', $command['arguments']),
            'description' => $this->translator->trans($command['description_key']),
            'example' => $this->generalHelpExampleFor($name),
        ];
    }

    protected function generalHelpExampleFor(string $name): ?string
    {
        return self::GENERAL_HELP_EXAMPLE_MAP[$name] ?? null;
    }
}
