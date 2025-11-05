<?php

declare(strict_types=1);

use App\DTO\Project;
use App\DTO\WorkItem;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\ProcessFactory;
use App\Handler\CommitHandler;
use App\Service\GithubProvider;
use App\Handler\StatusHandler;
use App\Handler\SubmitHandler;
use App\Handler\ItemListHandler;
use App\Handler\ItemStartHandler;
use App\Handler\ItemShowHandler;
use App\Handler\PleaseHandler;
use App\Handler\ProjectListHandler as ProjectsListHandler;
use App\Handler\SearchHandler;
use App\Handler\InitHandler;
use App\Service\FileSystem;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Yaml\Yaml;
use function Castor\io;
use function Castor\repack_path;
use function Castor\run;

#[AsTask(default: true)]
function main(): void
{
    help();
}

// =================================================================================
// Constants & Configuration
// =================================================================================

const CONFIG_DIR_NAME = '.config/stud';
const CONFIG_FILE_NAME = 'config.yml';
const DEFAULT_BASE_BRANCH = 'origin/develop';

// =================================================================================
// Helper Functions
// =================================================================================

/**
 * Returns the absolute path to the configuration file.
 */
function _get_config_path(): string
{
    $home = $_SERVER['HOME'] ?? throw new \RuntimeException('Could not determine home directory.');
    return rtrim($home, '/') . '/' . CONFIG_DIR_NAME . '/' . CONFIG_FILE_NAME;
}

/**
 * Reads configuration from the YAML file.
 * Throws an exception if the config is not found.
 */
function _get_config(): array
{
    $configPath = _get_config_path();
    if (!file_exists($configPath)) {
        io()->error([
            'Configuration file not found at: ' . $configPath,
            'Please run "stud config:init" to create one.',
        ]);
        exit(1);
    }

    return Yaml::parseFile($configPath);
}

/**
 * Gets and validates the Jira configuration.
 */
function _get_jira_config(): array
{
    $config = _get_config();
    $missingKeys = array_diff(['JIRA_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN'], array_keys($config));

    if (!empty($missingKeys)) {
        io()->error([
            'Your configuration file is missing required Jira keys: ' . implode(', ', $missingKeys),
            'Please run "stud config:init" again.',
        ]);
        exit(1);
    }

    return $config;
}

/**
 * Gets and validates the Git provider configuration.
 */
function _get_git_config(): array
{
    $config = _get_config();
    $missingKeys = array_diff(['GIT_PROVIDER', 'GIT_TOKEN', 'GIT_REPO_OWNER', 'GIT_REPO_NAME'], array_keys($config));

    if (!empty($missingKeys)) {
        io()->error([
            'Your configuration file is missing required Git provider keys: ' . implode(', ', $missingKeys),
            'Please run "stud config:init" again.',
        ]);
        exit(1);
    }

    return $config;
}

function _get_jira_service(): JiraService
{
    if (class_exists("\App\Tests\TestKernel::class") && \App\Tests\TestKernel::$jiraService) {
        return \App\Tests\TestKernel::$jiraService;
    }

    $config = _get_jira_config();
    $auth = base64_encode($config['JIRA_EMAIL'] . ':' . $config['JIRA_API_TOKEN']);

    $client = HttpClient::createForBaseUri($config['JIRA_URL'], [
        'headers' => [
            'User-Agent' => 'stud-cli',
            'Authorization' => 'Basic ' . $auth,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
    ]);

    return new App\Service\JiraService($client);
}

/**
 * Parses the Jira issue key from the current Git branch name.
 * Returns the key or null if not found.
 */
function _get_key_from_branch(): ?string
{
    return _get_git_repository()->getJiraKeyFromBranchName();
}

function _get_process_factory(): ProcessFactory
{
    if (class_exists("\App\Tests\TestKernel") && property_exists("\App\Tests\TestKernel", "processFactory") && \App\Tests\TestKernel::$processFactory) {
        return \App\Tests\TestKernel::$processFactory;
    }

    return new App\Service\ProcessFactory();
}

function _get_git_repository(): GitRepository
{
    if (class_exists("\App\Tests\TestKernel") && property_exists("\App\Tests\TestKernel", "gitRepository") && \App\Tests\TestKernel::$gitRepository) {
        return \App\Tests\TestKernel::$gitRepository;
    }

    return new App\Service\GitRepository(_get_process_factory());
}





// =================================================================================
// Configuration Command
// =================================================================================

#[AsTask(name: 'config:init', aliases: ['init'], description: 'Interactive wizard to set up Jira & Git connection details')]
function config_init(): void
{
    $handler = new App\Handler\InitHandler(new App\Service\FileSystem(), _get_config_path());
    $handler->handle(io());
}

// =================================================================================
// "Noun" Commands (Jira Info)
// =================================================================================

#[AsTask(name: 'projects:list', aliases: ['pj'], description: 'Lists all visible Jira projects')]
function projects_list(): void
{
    $handler = new App\Handler\ProjectListHandler(_get_jira_service());
    $handler->handle(io());
}

#[AsTask(name: 'items:list', aliases: ['ls'], description: 'Lists active work items (your dashboard)')]
function items_list(
    #[AsOption(name: 'all', shortcut: 'a', description: 'List items for all users')] bool $all = false,
    #[AsOption(name: 'project', shortcut: 'p', description: 'Filter by project key')] ?string $project = null
): void {
    $handler = new App\Handler\ItemListHandler(_get_jira_service());
    $handler->handle(io(), $all, $project);
}

#[AsTask(name: 'issues:search', aliases: ['search'], description: 'Search for issues using JQL')]
function issues_search(
    #[AsArgument(name: 'jql', description: 'The JQL query string')] string $jql
): void {
    $handler = new App\Handler\SearchHandler(_get_jira_service());
    $handler->handle(io(), $jql);
}


#[AsTask(name: 'items:show', aliases: ['sh'], description: 'Shows detailed info for one work item')]
function items_show(
    #[AsArgument(name: 'key', description: 'The Jira issue key (e.g., PROJ-123)')] string $key
): void {
    $handler = new App\Handler\ItemShowHandler(_get_jira_service(), _get_jira_config());
    $handler->handle(io(), $key);
}

// =================================================================================
// "Verb" Commands (Git Workflow)
// =================================================================================

#[AsTask(name: 'items:start', aliases: ['start'], description: 'Creates a new git branch from a Jira item')]
function items_start(
    #[AsArgument(name: 'key', description: 'The Jira issue key (e.g., PROJ-123)')] string $key
): void {
    $handler = new App\Handler\ItemStartHandler(_get_git_repository(), _get_jira_service(), DEFAULT_BASE_BRANCH);
    $handler->handle(io(), $key);
}

#[AsTask(name: 'commit', aliases: ['co'], description: 'Guides you through making a conventional commit')]
function commit(
    #[AsOption(name: 'new', description: 'Create a new logical commit instead of a fixup')] bool $isNew = false,
    #[AsOption(name: 'message', shortcut: 'm', description: 'Provide a commit message to bypass the prompter')] ?string $message = null
): void {
    $handler = new App\Handler\CommitHandler(_get_git_repository(), _get_jira_service(), DEFAULT_BASE_BRANCH);
    $handler->handle(io(), $isNew, $message);
}

#[AsTask(name: 'please', aliases: ['pl'], description: 'A power-user, safe force-push (force-with-lease)')]
function please(): void
{
    $handler = new App\Handler\PleaseHandler(_get_git_repository());
    $handler->handle(io());
}




#[AsTask(name: 'submit', aliases: ['su'], description: 'Pushes the current branch and creates a Pull Request')]
function submit(): void
{
    $gitConfig = _get_git_config();
    $githubProvider = null;
    if ($gitConfig['GIT_PROVIDER'] === 'github') {
        $githubProvider = new App\Service\GithubProvider(
            $gitConfig['GIT_TOKEN'],
            $gitConfig['GIT_REPO_OWNER'],
            $gitConfig['GIT_REPO_NAME']
        );
    }

    $handler = new App\Handler\SubmitHandler(
        _get_git_repository(),
        _get_jira_service(),
        $githubProvider,
        _get_jira_config(),
        DEFAULT_BASE_BRANCH
    );
    $handler->handle(io());
}

#[AsTask(name: 'help', description: 'Displays a list of available commands')]
function help(): void
{
    $logo = require './repack/logo.php';
    $appName = 'Stud Cli DX - Jira & Git Workflow Streamliner';
    $appVersion = '1.0.0';
    io()->writeln($logo($appName, $appVersion));
    io()->title('Manual');

    io()->section('DESCRIPTION');
    io()->writeln('    `stud-cli` is a command-line interface tool designed to streamline a developer\'s daily workflow by tightly integrating Jira work items with local Git repository operations. It guides you through the "golden path" of starting a task, making conventional commits, and preparing your work for submission, all from the command line.');

    io()->section('GLOBAL OPTIONS');
    io()->definitionList(
        ['-h, --help' => 'Display help for the given command. When no command is given, display help for the list command.'],
        ['-s, --silent' => 'Do not output any message.'],
        ['-q, --quiet' => 'Only errors are displayed. All other output is suppressed.'],
        ['-v|vv|vvv, --verbose' => 'Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug.'],
    );

    io()->section('COMMANDS');
    io()->writeln('  <info>stud</info> [command|alias] [-options] [arguments]');
    $commands = [
        'Configuration' => [
            [
                'name' => 'config:init',
                'alias' => 'init',
                'description' => 'Interactive wizard to set up Jira & Git connection details.',
            ],
        ],
        'Jira Information' => [
            [
                'name' => 'projects:list',
                'alias' => 'pj',
                'description' => 'Lists all visible Jira projects.',
            ],
            [
                'name' => 'items:list',
                'alias' => 'ls',
                'description' => 'Lists active work items (your dashboard).',
                'example' => 'stud ls -p PROJ',
            ],
            [
                'name' => 'items:show',
                'alias' => 'sh',
                'args' => '<key>',
                'description' => 'Shows detailed info for one work item.',
                'example' => 'stud sh PROJ-123',
            ],
            [
                'name' => 'issues:search',
                'alias' => 'search',
                'args' => '<jql>',
                'description' => 'Search for issues using JQL.',
                'example' => 'stud search "project = PROJ and status = Done"',
            ],
        ],
        'Git Workflow' => [
            [
                'name' => 'items:start',
                'alias' => 'start',
                'args' => '<key>',
                'description' => 'Creates a new git branch from a Jira item.',
                'example' => 'stud start PROJ-123',
            ],
            [
                'name' => 'commit',
                'alias' => 'co',
                'description' => 'Guides you through making a conventional commit.',
            ],
            [
                'name' => 'please',
                'alias' => 'pl',
                'description' => 'A power-user, safe force-push (force-with-lease).',
            ],
            [
                'name' => 'submit',
                'alias' => 'su',
                'description' => 'Pushes the current branch and creates a Pull Request.',
            ],
            [
                'name' => 'status',
                'alias' => 'ss',
                'description' => 'A quick "where am I?" dashboard.',
            ],
        ],
    ];

    foreach ($commands as $category => $commandList) {
        io()->writeln("\n  <fg=yellow>{$category}</>");
        $tableRows = [];
        foreach ($commandList as $command) {
            $name = $command['name'];
            if (isset($command['args'])) {
                $name .= ' ' . $command['args'];
            }

            $description = $command['description'];
            if (isset($command['example'])) {
                $description .= "\n<fg=gray>Example: {$command['example']}</>";
            }

            $tableRows[] = [
                $name,
                $command['alias'] ?? '',
                $description,
            ];
        }
        io()->table(['Command', 'Alias', 'Description'], $tableRows);
    }
}


#[AsTask(name: 'status', aliases: ['ss'], description: 'A quick "where am I?" dashboard')]
function status(): void
{
    $handler = new App\Handler\StatusHandler(_get_git_repository(), _get_jira_service());
    $handler->handle(io());
}
