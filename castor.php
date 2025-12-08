<?php

declare(strict_types=1);

// =================================================================================
// Constants & Configuration
// =================================================================================

if (! defined('CONFIG_DIR_NAME')) {
    define('CONFIG_DIR_NAME', '.config/stud');
}
if (! defined('CONFIG_FILE_NAME')) {
    define('CONFIG_FILE_NAME', 'config.yml');
}
if (! defined('DEFAULT_BASE_BRANCH')) {
    define('DEFAULT_BASE_BRANCH', 'origin/develop');
}


use App\Handler\CacheClearHandler;
use App\Handler\CommitHandler;
use App\Handler\DeployHandler;
use App\Handler\FlattenHandler;
use App\Handler\InitHandler;
use App\Handler\ItemListHandler;
use App\Handler\ItemShowHandler;
use App\Handler\ItemStartHandler;
use App\Handler\PleaseHandler;
use App\Handler\PrCommentHandler;
use App\Handler\ProjectListHandler;
use App\Handler\ReleaseHandler;
use App\Handler\SearchHandler;
use App\Handler\StatusHandler;
use App\Handler\SubmitHandler;
use App\Handler\UpdateHandler;
use App\Service\ChangelogParser;
use App\Service\FileSystem;
use App\Service\GithubProvider;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\ProcessFactory;
use App\Service\TranslationService;
use App\Service\UpdateFileService;
use App\Service\VersionCheckService;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsListener;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

use function Castor\io;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Yaml\Yaml;

// Cleanup old backup files from previous updates
// This runs on every command execution to clean up versioned backup files (e.g., stud-1.1.1.bak)
if (class_exists('Phar') && \Phar::running(false)) {
    $executablePath = \Phar::running(false);
    $executableDir = dirname($executablePath);
    $executableName = basename($executablePath);

    // Glob for backup files matching the pattern: {executable-name}-*.bak
    $backupPattern = $executableDir . '/' . $executableName . '-*.bak';
    $backupFiles = glob($backupPattern);

    if ($backupFiles !== false) {
        foreach ($backupFiles as $backupFile) {
            @unlink($backupFile);
        }
    }
}

#[AsTask(default: true)]
function main(): void
{
    _load_constants();
    help();
}

function _load_constants(): void
{
    require_once __DIR__ . '/src/config/constants.php';
}

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
 *
 * This function also triggers the version check on first call (after app initialization).
 *
 * @return array<string, mixed>
 */
function _get_config(): array
{
    // Perform version check once when config is first accessed (app is initialized)
    // Use static variable to ensure it only runs once
    static $versionCheckDone = false;
    if (! $versionCheckDone) {
        $versionCheckDone = true;

        try {
            _version_check_bootstrap();
        } catch (\Throwable $e) {
            // Silently fail - don't block config loading or build process
        }
    }

    $configPath = _get_config_path();
    if (! file_exists($configPath)) {
        $translator = _get_translation_service();
        io()->error(explode("\n", $translator->trans('config.error.not_found', ['path' => $configPath])));
        exit(1);
    }

    return Yaml::parseFile($configPath);
}

/**
 * Gets and validates the Jira configuration.
 *
 * @return array<string, mixed>
 */
function _get_jira_config(): array
{
    $config = _get_config();
    $missingKeys = array_diff(['JIRA_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN'], array_keys($config));

    if (! empty($missingKeys)) {
        $translator = _get_translation_service();
        io()->error(explode("\n", $translator->trans('config.error.missing_jira_keys', ['keys' => implode(', ', $missingKeys)])));
        exit(1);
    }

    return $config;
}

/**
 * Gets and validates the Git provider configuration.
 *
 * @return array<string, mixed>
 */
function _get_git_config(): array
{
    $config = _get_config();
    $missingKeys = array_diff(['GIT_PROVIDER', 'GIT_TOKEN'], array_keys($config));

    if (! empty($missingKeys)) {
        $translator = _get_translation_service();
        io()->error(explode("\n", $translator->trans('config.error.missing_git_keys', ['keys' => implode(', ', $missingKeys)])));
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

    return new JiraService($client);
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

    return new ProcessFactory();
}

function _get_git_repository(): GitRepository
{
    if (class_exists("\App\Tests\TestKernel") && property_exists("\App\Tests\TestKernel", "gitRepository") && \App\Tests\TestKernel::$gitRepository) {
        return \App\Tests\TestKernel::$gitRepository;
    }

    return new GitRepository(_get_process_factory());
}

function _get_translation_service(): TranslationService
{
    if (class_exists("\App\Tests\TestKernel") && property_exists("\App\Tests\TestKernel", "translationService") && \App\Tests\TestKernel::$translationService) {
        return \App\Tests\TestKernel::$translationService;
    }

    // Get locale from config, default to 'en'
    // Check if config file exists before calling _get_config() to avoid circular dependency
    // when config file doesn't exist (e.g., during first run/config:init)
    $locale = 'en';
    $configPath = _get_config_path();

    if (file_exists($configPath)) {
        try {
            // Config file exists, safe to call _get_config()
            $config = _get_config();
            $locale = $config['LANGUAGE'] ?? 'en';
        } catch (\Exception $e) {
            // Config file exists but is unreadable or contains invalid YAML.
            // Silently fall back to default 'en' locale to avoid blocking initialization.
            // This is intentional: translation service must be available even if config is corrupted.
            // The error will be surfaced when _get_config() is called elsewhere (e.g., by commands that require config).
        }
    }
    // If config file doesn't exist, use default 'en' locale (no need to call _get_config())

    // Determine translations path - works both in PHAR and development
    $translationsPath = __DIR__ . '/src/resources/translations';
    if (class_exists('Phar') && \Phar::running(false)) {
        // When running as PHAR, use the PHAR path
        $translationsPath = 'phar://' . \Phar::running(false) . '/src/resources/translations';
    }

    return new TranslationService($locale, $translationsPath);
}

/**
 * Global variable to store the update message for display at command termination.
 */
$GLOBALS['_version_check_message'] = null;

/**
 * Performs a version check at bootstrap.
 * This function runs silently and stores the result for later display.
 *
 * @codeCoverageIgnore - This function is difficult to test as it runs at bootstrap
 */
function _version_check_bootstrap(): void
{
    // Skip version check during PHAR compilation/build process
    // During castor repack, the execution context is not suitable for this check
    // We use multiple checks to detect if we're in a safe execution context

    // Check 1: Ensure we're in CLI mode (not during compilation analysis)
    if (php_sapi_name() !== 'cli') {
        return;
    }

    // Check 2: Ensure constants file exists (it won't exist during initial build)
    $constantsPath = __DIR__ . '/src/config/constants.php';
    if (! file_exists($constantsPath)) {
        // Constants file doesn't exist (likely during build)
        return;
    }

    // Check 3: Don't use class_exists() as it can trigger autoloading which causes segfault during compilation
    // Instead, check if we can safely proceed by verifying the file exists
    $serviceFile = __DIR__ . '/src/Service/VersionCheckService.php';
    if (! file_exists($serviceFile)) {
        // Service file doesn't exist (likely during build or incomplete installation)
        return;
    }

    // Load constants
    try {
        require_once $constantsPath;
    } catch (\Throwable $e) {
        // Any error loading constants means we should skip
        return;
    }

    if (! defined('APP_VERSION') || ! defined('APP_REPO_SLUG')) {
        // Constants not defined, skip check
        return;
    }

    // Parse repository owner and name from APP_REPO_SLUG
    $nameParts = explode('/', APP_REPO_SLUG, 2);
    if (count($nameParts) !== 2) {
        return;
    }

    [$repoOwner, $repoName] = $nameParts;

    // Get Git token from config if available (needed for private repositories)
    // Read config file directly to avoid circular dependency with _get_config()
    $gitToken = null;

    try {
        $configPath = _get_config_path();
        if (file_exists($configPath)) {
            $config = Yaml::parseFile($configPath);
            $gitToken = $config['GIT_TOKEN'] ?? null;
        }
    } catch (\Throwable $e) {
        // Config might not exist or be unreadable, that's okay - we'll try without token
    }

    // Perform version check with comprehensive error handling
    try {
        $versionCheckService = new VersionCheckService(
            $repoOwner,
            $repoName,
            APP_VERSION,
            $gitToken
        );

        $result = $versionCheckService->checkForUpdate();

        if ($result['should_display'] && $result['latest_version'] !== null) {
            $GLOBALS['_version_check_message'] = $result['latest_version'];
        }
    } catch (\Throwable $e) {
        // Fail silently - don't block the user's command or build process
        // Catch Throwable (not just Exception) to catch all errors including fatal ones
    }
}

/**
 * Displays the version update warning at the end of command execution.
 */
#[AsListener(event: ConsoleEvents::TERMINATE)]
function _version_check_listener(ConsoleTerminateEvent $event): void
{
    if (! isset($GLOBALS['_version_check_message'])) {
        return;
    }

    /** @var string|null $latestVersion */
    $latestVersion = $GLOBALS['_version_check_message'];
    if ($latestVersion === null) {
        return;
    }
    $output = $event->getOutput();

    // Only display if output is not quiet
    if ($output->isQuiet()) {
        return;
    }

    $io = new SymfonyStyle($event->getInput(), $output);
    $io->warning(sprintf(
        "A new version (v%s) is available. Run 'stud up' to update.",
        $latestVersion
    ));
}

// =================================================================================
// Configuration Command
// =================================================================================

#[AsTask(name: 'config:init', aliases: ['init'], description: 'Interactive wizard to set up Jira & Git connection details')]
function config_init(): void
{
    _load_constants();
    $handler = new InitHandler(new FileSystem(), _get_config_path(), _get_translation_service());
    $handler->handle(io());
}

// =================================================================================
// "Noun" Commands (Jira Info)
// =================================================================================

#[AsTask(name: 'projects:list', aliases: ['pj'], description: 'Lists all visible Jira projects')]
function projects_list(

): void {
    _load_constants();
    $handler = new ProjectListHandler(_get_jira_service(), _get_translation_service());
    $handler->handle(io());
}

#[AsTask(name: 'items:list', aliases: ['ls'], description: 'Lists active work items (your dashboard)')]
function items_list(
    #[AsOption(name: 'all', shortcut: 'a', description: 'List items for all users')]
    bool $all = false,
    #[AsOption(name: 'project', shortcut: 'p', description: 'Filter by project key')]
    ?string $project = null,
): void {
    _load_constants();
    $handler = new ItemListHandler(_get_jira_service(), _get_translation_service());
    $handler->handle(io(), $all, $project);
}

#[AsTask(name: 'items:search', aliases: ['search'], description: 'Search for issues using JQL')]
function items_search(
    #[AsArgument(name: 'jql', description: 'The JQL query string')]
    string $jql,
): void {
    _load_constants();
    $handler = new SearchHandler(_get_jira_service(), _get_translation_service());
    $handler->handle(io(), $jql);
}


#[AsTask(name: 'items:show', aliases: ['sh'], description: 'Shows detailed info for one work item')]
function items_show(
    #[AsArgument(name: 'key', description: 'The Jira issue key (e.g., PROJ-123)')]
    string $key,
): void {
    _load_constants();
    $handler = new ItemShowHandler(_get_jira_service(), _get_jira_config(), _get_translation_service());
    $handler->handle(io(), $key);
}

// =================================================================================
// "Verb" Commands (Git Workflow)
// =================================================================================

#[AsTask(name: 'items:start', aliases: ['start'], description: 'Creates a new git branch from a Jira item')]
function items_start(
    #[AsArgument(name: 'key', description: 'The Jira issue key (e.g., PROJ-123)')]
    string $key,
): void {
    _load_constants();
    $handler = new ItemStartHandler(_get_git_repository(), _get_jira_service(), DEFAULT_BASE_BRANCH, _get_translation_service(), _get_jira_config());
    $handler->handle(io(), $key);
}

#[AsTask(name: 'commit', aliases: ['co'], description: 'Guides you through making a conventional commit')]
function commit(
    #[AsOption(name: 'new', description: 'Create a new logical commit instead of a fixup')]
    bool $isNew = false,
    #[AsOption(name: 'message', shortcut: 'm', description: 'Provide a commit message to bypass the prompter')]
    ?string $message = null,
    #[AsOption(name: 'help', shortcut: 'h', description: 'Display help for this command')]
    bool $help = false
): void {
    _load_constants();
    if ($help) {
        $helpService = new \App\Service\HelpService(_get_translation_service());
        $helpService->displayCommandHelp(io(), 'commit');

        return;
    }
    $handler = new CommitHandler(_get_git_repository(), _get_jira_service(), DEFAULT_BASE_BRANCH, _get_translation_service());
    $handler->handle(io(), $isNew, $message);
}

#[AsTask(name: 'please', aliases: ['pl'], description: 'A power-user, safe force-push (force-with-lease)')]
function please(

): void {
    _load_constants();
    $handler = new PleaseHandler(_get_git_repository(), _get_translation_service());
    $handler->handle(io());
}

#[AsTask(name: 'flatten', aliases: ['ft'], description: 'Automatically squash all fixup! commits into their target commits')]
function flatten(

): void {
    _load_constants();
    $handler = new FlattenHandler(_get_git_repository(), DEFAULT_BASE_BRANCH, _get_translation_service());
    exit($handler->handle(io()));
}

#[AsTask(name: 'cache:clear', aliases: ['cc'], description: 'Clear the update check cache to force a version check on next command')]
function cache_clear(

): void {
    _load_constants();
    $handler = new CacheClearHandler(_get_translation_service());
    exit($handler->handle(io()));
}

#[AsTask(name: 'submit', aliases: ['su'], description: 'Pushes the current branch and creates a Pull Request')]
function submit(
    #[AsOption(name: 'draft', shortcut: 'd', description: 'Create a Draft Pull Request')]
    bool $draft = false,
    #[AsOption(name: 'labels', description: 'Comma-separated list of labels to apply to the Pull Request')]
    ?string $labels = null,
): void {
    _load_constants();
    _load_constants();
    $gitConfig = _get_git_config();
    $gitRepository = _get_git_repository();

    $githubProvider = null;
    if ($gitConfig['GIT_PROVIDER'] === 'github') {
        // Get repository owner and name from git remote at runtime
        $repoOwner = $gitRepository->getRepositoryOwner();
        $repoName = $gitRepository->getRepositoryName();

        if (io()->isVerbose()) {
            io()->writeln("  <fg=gray>Detected repository owner: '{$repoOwner}'</>");
            io()->writeln("  <fg=gray>Detected repository name: '{$repoName}'</>");
        }

        if (! $repoOwner || ! $repoName) {
            io()->error([
                'Could not determine repository owner or name from git remote.',
                'Please ensure your repository has a remote named "origin" configured.',
                'You can check with: git remote -v',
            ]);
            exit(1);
        }

        if (io()->isVerbose()) {
            io()->writeln("  <fg=gray>Creating GitHub provider with owner='{$repoOwner}' and repo='{$repoName}'</>");
        }

        $githubProvider = new GithubProvider(
            $gitConfig['GIT_TOKEN'],
            $repoOwner,
            $repoName
        );
    }

    $handler = new SubmitHandler(
        $gitRepository,
        _get_jira_service(),
        $githubProvider,
        _get_jira_config(),
        DEFAULT_BASE_BRANCH,
        _get_translation_service()
    );
    $handler->handle(io(), $draft, $labels);
}

#[AsTask(name: 'pr:comment', aliases: ['pc'], description: 'Posts a comment to the active Pull Request')]
function pr_comment(
    #[AsArgument(name: 'message', description: 'The comment message (optional if piping from STDIN)')]
    ?string $message = null,
): void {
    _load_constants();
    $gitConfig = _get_git_config();
    $gitRepository = _get_git_repository();

    $githubProvider = null;
    if ($gitConfig['GIT_PROVIDER'] === 'github') {
        // Get repository owner and name from git remote at runtime
        $repoOwner = $gitRepository->getRepositoryOwner();
        $repoName = $gitRepository->getRepositoryName();

        if (io()->isVerbose()) {
            io()->writeln("  <fg=gray>Detected repository owner: '{$repoOwner}'</>");
            io()->writeln("  <fg=gray>Detected repository name: '{$repoName}'</>");
        }

        if (! $repoOwner || ! $repoName) {
            io()->error([
                'Could not determine repository owner or name from git remote.',
                'Please ensure your repository has a remote named "origin" configured.',
                'You can check with: git remote -v',
            ]);
            exit(1);
        }

        if (io()->isVerbose()) {
            io()->writeln("  <fg=gray>Creating GitHub provider with owner='{$repoOwner}' and repo='{$repoName}'</>");
        }

        $githubProvider = new GithubProvider(
            $gitConfig['GIT_TOKEN'],
            $repoOwner,
            $repoName
        );
    }

    $handler = new PrCommentHandler(
        $gitRepository,
        $githubProvider,
        _get_translation_service()
    );
    $exitCode = $handler->handle(io(), $message);
    exit($exitCode);
}

#[AsTask(name: 'help', description: 'Displays a list of available commands')]
function help(
    #[AsArgument(name: 'command_name', description: 'The command name to get help for')]
    ?string $commandName = null
): void {
    _load_constants();
    $translator = _get_translation_service();

    // If a command is provided, show help for that specific command
    if ($commandName !== null) {
        $helpService = new \App\Service\HelpService($translator);

        // Map aliases to command names
        $aliasMap = [
            'init' => 'config:init',
            'pj' => 'projects:list',
            'ls' => 'items:list',
            'search' => 'items:search',
            'sh' => 'items:show',
            'start' => 'items:start',
            'co' => 'commit',
            'pl' => 'please',
            'su' => 'submit',
            'pc' => 'pr:comment',
            'ss' => 'status',
            'rl' => 'release',
            'mep' => 'deploy',
        ];

        $mappedCommandName = $aliasMap[$commandName] ?? $commandName;
        $helpService->displayCommandHelp(io(), $mappedCommandName);

        return;
    }

    // Otherwise, show general help
    $logo = require APP_LOGO_PATH;
    io()->writeln($logo(APP_NAME, APP_VERSION));
    io()->title($translator->trans('help.title'));

    io()->section($translator->trans('help.description_section'));
    io()->writeln('    ' . $translator->trans('help.description_text'));
    // io()->newLine();
    // io()->note($translator->trans('help.universal_help_note'));
    io()->newLine();
    io()->note($translator->trans('help.command_specific_help_note'));

    io()->section($translator->trans('help.global_options_section'));
    io()->definitionList(
        // ['-h, --help' => $translator->trans('help.global_option_help')],
        // ['-s, --silent' => $translator->trans('help.global_option_silent')],
        // ['-q, --quiet' => $translator->trans('help.global_option_quiet')],
        ['-v|vv|vvv, --verbose' => $translator->trans('help.global_option_verbose')],
    );

    io()->section($translator->trans('help.commands_section'));
    io()->writeln('  <info>stud</info> [command|alias] [-options] [arguments]');
    $commands = [
        $translator->trans('help.category_configuration') => [
            [
                'name' => 'config:init',
                'alias' => 'init',
                'description' => $translator->trans('help.command_config_init'),
            ],
            [
                'name' => 'completion',
                'args' => '<shell>',
                'description' => $translator->trans('help.command_completion'),
                'example' => 'stud completion bash',
            ],
        ],
        $translator->trans('help.category_jira_information') => [
            [
                'name' => 'projects:list',
                'alias' => 'pj',
                'description' => $translator->trans('help.command_projects_list'),
            ],
            [
                'name' => 'items:list',
                'alias' => 'ls',
                'description' => $translator->trans('help.command_items_list'),
                'example' => 'stud ls -p PROJ',
            ],
            [
                'name' => 'items:show',
                'alias' => 'sh',
                'args' => '<key>',
                'description' => $translator->trans('help.command_items_show'),
                'example' => 'stud sh PROJ-123',
            ],
            [
                'name' => 'items:search',
                'alias' => 'search',
                'args' => '<jql>',
                'description' => $translator->trans('help.command_items_search'),
                'example' => 'stud search "project = PROJ and status = Done"',
            ],
        ],
        $translator->trans('help.category_git_workflow') => [
            [
                'name' => 'items:start',
                'alias' => 'start',
                'args' => '<key>',
                'description' => $translator->trans('help.command_items_start'),
                'example' => 'stud start PROJ-123',
            ],
            [
                'name' => 'commit',
                'alias' => 'co',
                'description' => $translator->trans('help.command_commit'),
            ],
            [
                'name' => 'please',
                'alias' => 'pl',
                'description' => $translator->trans('help.command_please'),
            ],
            [
                'name' => 'submit',
                'alias' => 'su',
                'description' => $translator->trans('help.command_submit'),
            ],
            [
                'name' => 'status',
                'alias' => 'ss',
                'description' => $translator->trans('help.command_status'),
            ],
        ],
        $translator->trans('help.category_release_commands') => [
            [
                'name' => 'release',
                'alias' => 'rl',
                'args' => '<version>',
                'description' => $translator->trans('help.command_release'),
                'example' => 'stud release 1.2.0',
            ],
            [
                'name' => 'deploy',
                'alias' => 'mep',
                'description' => $translator->trans('help.command_deploy'),
                'example' => 'stud deploy',
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
                $description .= "\n<fg=gray>" . $translator->trans('help.example_prefix', ['example' => $command['example']]) . "</>";
            }

            $tableRows[] = [
                $name,
                $command['alias'] ?? '',
                $description,
            ];
        }
        io()->table([
            $translator->trans('table.command'),
            $translator->trans('table.alias'),
            $translator->trans('table.description'),
        ], $tableRows);
    }
}


#[AsTask(name: 'status', aliases: ['ss'], description: 'A quick "where am I?" dashboard')]
function status(

): void {
    _load_constants();
    $handler = new StatusHandler(_get_git_repository(), _get_jira_service(), _get_translation_service());
    $handler->handle(io());
}

// =================================================================================
// Release Commands
// =================================================================================

#[AsTask(name: 'release', aliases: ['rl'], description: 'Creates a new release branch and bumps the version')]
function release(
    #[AsArgument(name: 'version', description: 'The new version (e.g., 1.2.0). Optional if using --major, --minor, or --patch flags')]
    ?string $version = null,
    #[AsOption(name: 'major', shortcut: 'M', description: 'Increment major version (X.0.0)')]
    bool $major = false,
    #[AsOption(name: 'minor', shortcut: 'm', description: 'Increment minor version (X.Y.0)')]
    bool $minor = false,
    #[AsOption(name: 'patch', shortcut: 'b', description: 'Increment patch version (X.Y.Z). This is the default if no flags are provided')]
    bool $patch = false,
    #[AsOption(name: 'publish', shortcut: 'p', description: 'Publish the release branch to the remote')]
    bool $publish = false,
): void {
    _load_constants();

    // Validate mutually exclusive flags
    $flagCount = ($major ? 1 : 0) + ($minor ? 1 : 0) + ($patch ? 1 : 0);
    if ($flagCount > 1) {
        io()->error('Only one of --major, --minor, or --patch can be specified at a time.');
        exit(1);
    }

    // Determine bump type
    $bumpType = null;
    if ($major) {
        $bumpType = 'major';
    } elseif ($minor) {
        $bumpType = 'minor';
    } elseif ($patch) {
        $bumpType = 'patch';
    } elseif ($version === null) {
        // Default to patch if no version and no flags
        $bumpType = 'patch';
    }

    // If version is provided, flags should not be used
    if ($version !== null && $bumpType !== null) {
        io()->error('Cannot specify both a version and a bump flag (--major, --minor, --patch).');
        exit(1);
    }

    $handler = new ReleaseHandler(_get_git_repository(), _get_translation_service());
    $handler->handle(io(), $version, $publish, $bumpType);
}

#[AsTask(name: 'deploy', aliases: ['mep'], description: 'Deploys the current release branch')]
function deploy(

): void {
    _load_constants();
    $handler = new DeployHandler(_get_git_repository(), _get_translation_service());
    $handler->handle(io());
}

#[AsTask(name: 'update', aliases: ['up'], description: 'Checks for and installs new versions of the tool')]
function update(
    #[AsOption(name: 'info', shortcut: 'i', description: 'Preview the changelog of the latest available version without downloading')]
    bool $info = false,
): void {
    _load_constants();

    // Get binary path - try Phar first, then fallback
    $binaryPath = '';
    if (class_exists('Phar') && \Phar::running(false)) {
        $binaryPath = \Phar::running(false);
    } else {
        // Fallback for development/testing
        $binaryPath = __FILE__;
    }

    // Get Git token from config if available (needed for private repositories)
    $gitToken = null;

    try {
        $gitConfig = _get_git_config();
        $gitToken = $gitConfig['GIT_TOKEN'] ?? null;
    } catch (\Exception $e) {
        // Config might not exist, that's okay - we'll try without token
    }

    // Get repository owner and name from APP_REPO_SLUG constant
    // This constant is baked into the PHAR during build via dump-config
    if (! defined('APP_REPO_SLUG')) {
        io()->error([
            'APP_REPO_SLUG constant is not defined.',
            'Please ensure the application was built correctly.',
        ]);
        exit(1);
    }

    // Parse "owner/repo" format from APP_REPO_SLUG constant
    $nameParts = explode('/', APP_REPO_SLUG, 2);
    if (count($nameParts) !== 2) {
        io()->error([
            'APP_REPO_SLUG must be in "owner/repo" format.',
            'Current value: ' . APP_REPO_SLUG,
        ]);
        exit(1);
    }

    [$repoOwner, $repoName] = $nameParts;

    $handler = new UpdateHandler(
        $repoOwner,
        $repoName,
        APP_VERSION,
        $binaryPath,
        _get_translation_service(),
        new ChangelogParser(),
        new UpdateFileService(_get_translation_service()),
        $gitToken
    );
    $result = $handler->handle(io(), $info);
    exit($result);
}
