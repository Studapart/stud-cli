<?php

declare(strict_types=1);

// When running as repacked PHAR (Castor 1.3+), the stub only loads .castor-vendor; load project autoload so App\ is available.
if (\extension_loaded('Phar') && \Phar::running(false) !== '') {
    require_once 'phar://' . \Phar::running(false) . '/vendor/autoload.php';
}

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


use App\Attribute\AgentOutput;
use App\DTO\ConfluencePushInput;
use App\DTO\ConfluenceShowInput;
use App\DTO\ItemCreateInput;
use App\DTO\ItemUpdateInput;
use App\DTO\ItemUploadInput;
use App\Enum\OutputFormat;
use App\Exception\AgentModeException;
use App\Handler\BranchCleanHandler;
use App\Handler\BranchListHandler;
use App\Handler\BranchRenameHandler;
use App\Handler\CacheClearHandler;
use App\Handler\CommitHandler;
use App\Handler\ConfigProjectInitHandler;
use App\Handler\ConfigProjectInitPromptCollector;
use App\Handler\ConfigShowHandler;
use App\Handler\ConfigValidateHandler;
use App\Handler\ConfluencePushHandler;
use App\Handler\ConfluenceShowHandler;
use App\Handler\DeployHandler;
use App\Handler\FilterListHandler;
use App\Handler\FilterShowHandler;
use App\Handler\FlattenHandler;
use App\Handler\InitHandler;
use App\Handler\ItemCreateHandler;
use App\Handler\ItemDownloadHandler;
use App\Handler\ItemListHandler;
use App\Handler\ItemShowHandler;
use App\Handler\ItemStartHandler;
use App\Handler\ItemTakeoverHandler;
use App\Handler\ItemTransitionHandler;
use App\Handler\ItemUpdateHandler;
use App\Handler\ItemUploadHandler;
use App\Handler\PleaseHandler;
use App\Handler\PrCommentHandler;
use App\Handler\PrCommentsHandler;
use App\Handler\ProjectListHandler;
use App\Handler\PushHandler;
use App\Handler\ReleaseHandler;
use App\Handler\SearchHandler;
use App\Handler\StatusHandler;
use App\Handler\SubmitHandler;
use App\Handler\SyncHandler;
use App\Handler\UpdateHandler;
use App\Responder\AgentCommandResponder;
use App\Responder\BranchListResponder;
use App\Responder\ConfigProjectInitResponder;
use App\Responder\ConfigShowResponder;
use App\Responder\ConfigValidateResponder;
use App\Responder\ConfluencePushResponder;
use App\Responder\ConfluenceShowResponder;
use App\Responder\ErrorResponder;
use App\Responder\FilterListResponder;
use App\Responder\FilterShowResponder;
use App\Responder\ItemCreateResponder;
use App\Responder\ItemDownloadResponder;
use App\Responder\ItemListResponder;
use App\Responder\ItemShowResponder;
use App\Responder\ItemUpdateResponder;
use App\Responder\ItemUploadResponder;
use App\Responder\PrCommentsResponder;
use App\Responder\ProjectListResponder;
use App\Responder\SearchResponder;
use App\Response\AgentJsonResponse;
use App\Service\AgentModeHelper;
use App\Service\ChangelogParser;
use App\Service\CommandMap;
use App\Service\ConfigValidator;
use App\Service\ConfluenceService;
use App\Service\FileSystem;
use App\Service\GitBranchService;
use App\Service\GithubProvider;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use App\Service\InitProjectConfigFollowUpService;
use App\Service\JiraAttachmentService;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\MigrationExecutor;
use App\Service\MigrationRegistry;
use App\Service\ProcessFactory;
use App\Service\ProjectStudConfigAdequacyChecker;
use App\Service\ThemeDetector;
use App\Service\TranslationService;
use App\Service\UpdateFileService;
use App\Service\VersionCheckService;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsListener;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

use function Castor\input;
use function Castor\io;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputOption;
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
    $home = $_SERVER['HOME'] ?? getenv('HOME') ?: null;
    if ($home === null) {
        throw new \RuntimeException('Could not determine home directory (set HOME or ensure it is in the environment).');
    }
    if (str_starts_with((string) $home, 'phar://')) {
        throw new \RuntimeException('HOME must be a real filesystem path; when running from a PHAR, set HOME to your home directory (e.g. /Users/yourname).');
    }

    return rtrim($home, '/') . '/' . CONFIG_DIR_NAME . '/' . CONFIG_FILE_NAME;
}

/**
 * Reads configuration from the YAML file.
 * Throws an exception if the config is not found.
 *
 * This function also triggers the version check on first call (after app initialization).
 * Note: Migrations are now handled by the _config_pass_listener, not in this function.
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
        _get_logger()->error(Logger::VERBOSITY_NORMAL, explode("\n", $translator->trans('config.error.not_found', ['path' => $configPath])));
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
        _get_logger()->error(Logger::VERBOSITY_NORMAL, explode("\n", $translator->trans('config.error.missing_jira_keys', ['keys' => implode(', ', $missingKeys)])));
        exit(1);
    }

    return $config;
}

/**
 * Gets the Git provider token configuration from global config.
 * Tokens are optional - at least one should be configured for Git operations.
 *
 * @return array<string, mixed> Global git token configuration
 */
function _get_git_config(): array
{
    $config = _get_config();

    // Tokens are optional - we don't require them to be present
    // The provider will be determined per-repository
    return [
        'GITHUB_TOKEN' => $config['GITHUB_TOKEN'] ?? null,
        'GITLAB_TOKEN' => $config['GITLAB_TOKEN'] ?? null,
        'GITLAB_INSTANCE_URL' => $config['GITLAB_INSTANCE_URL'] ?? null,
    ];
}

function _get_html_converter(): \App\Service\JiraHtmlConverter
{
    return new \App\Service\JiraHtmlConverter(null, _get_logger());
}

function _get_jira_http_client(): \Symfony\Contracts\HttpClient\HttpClientInterface
{
    $config = _get_jira_config();
    $auth = base64_encode($config['JIRA_EMAIL'] . ':' . $config['JIRA_API_TOKEN']);

    return HttpClient::createForBaseUri($config['JIRA_URL'], [
        'headers' => [
            'User-Agent' => 'stud-cli',
            'Authorization' => 'Basic ' . $auth,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
    ]);
}

function _get_jira_service(): JiraService
{
    if (class_exists("\App\Tests\TestKernel::class") && \App\Tests\TestKernel::$jiraService) {
        return \App\Tests\TestKernel::$jiraService;
    }

    return new JiraService(_get_jira_http_client(), _get_html_converter());
}

function _get_jira_attachment_service(): JiraAttachmentService
{
    if (class_exists("\App\Tests\TestKernel::class") && \App\Tests\TestKernel::$jiraAttachmentService !== null) {
        return \App\Tests\TestKernel::$jiraAttachmentService;
    }

    $config = _get_jira_config();

    return new JiraAttachmentService(
        _get_jira_http_client(),
        rtrim((string) $config['JIRA_URL'], '/'),
    );
}

/**
 * Returns the Confluence base URL (e.g. https://domain.atlassian.net/wiki).
 * Priority: $urlOverride > CONFLUENCE_URL config > derived from JIRA_URL.
 */
function _get_confluence_base_url(?string $urlOverride = null): string
{
    if ($urlOverride !== null && trim($urlOverride) !== '') {
        return rtrim(trim($urlOverride), '/');
    }
    $config = _get_jira_config();
    $explicit = $config['CONFLUENCE_URL'] ?? null;
    if ($explicit !== null && trim((string) $explicit) !== '') {
        return rtrim(trim((string) $explicit), '/');
    }
    $jiraUrl = (string) ($config['JIRA_URL'] ?? '');
    $parsed = parse_url($jiraUrl);
    $scheme = $parsed['scheme'] ?? 'https';
    $host = $parsed['host'] ?? '';

    return $scheme . '://' . $host . '/wiki';
}

function _get_confluence_service(?string $urlOverride = null): ConfluenceService
{
    if (class_exists("\App\Tests\TestKernel") && property_exists("\App\Tests\TestKernel", "confluenceService") && \App\Tests\TestKernel::$confluenceService !== null) {
        return \App\Tests\TestKernel::$confluenceService;
    }
    $baseUrl = rtrim(_get_confluence_base_url($urlOverride), '/') . '/';
    $config = _get_jira_config();
    $auth = base64_encode($config['JIRA_EMAIL'] . ':' . $config['JIRA_API_TOKEN']);
    $client = HttpClient::createForBaseUri($baseUrl, [
        'headers' => [
            'User-Agent' => 'stud-cli',
            'Authorization' => 'Basic ' . $auth,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
    ]);

    return new ConfluenceService($client);
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

    return new GitRepository(_get_process_factory(), _get_file_system());
}

function _get_git_branch_service(): GitBranchService
{
    if (class_exists("\App\Tests\TestKernel") && property_exists("\App\Tests\TestKernel", "gitBranchService") && \App\Tests\TestKernel::$gitBranchService) {
        return \App\Tests\TestKernel::$gitBranchService;
    }

    return new GitBranchService(_get_git_repository());
}

function _get_git_setup_service(): GitSetupService
{
    return new GitSetupService(
        _get_git_repository(),
        _get_git_branch_service(),
        _get_logger(),
        _get_translation_service()
    );
}

function _get_init_project_config_follow_up_service(): InitProjectConfigFollowUpService
{
    $gitRepository = _get_git_repository();
    $gitSetup = _get_git_setup_service();
    $promptCollector = new ConfigProjectInitPromptCollector(
        $gitRepository,
        $gitSetup,
        _get_translation_service(),
        _get_logger(),
        new \App\Service\GitTokenPromptResolver()
    );
    $projectInitHandler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $promptCollector);

    return new InitProjectConfigFollowUpService(
        $gitRepository,
        new ProjectStudConfigAdequacyChecker(),
        $projectInitHandler,
        new ConfigProjectInitResponder(_get_responder_helper(), _get_logger()),
        _get_translation_service(),
        _get_logger(),
    );
}

/**
 * Gets the appropriate Git provider based on configuration.
 * Reads provider from project config, tokens from project config (with global fallback).
 * Prompts user to configure provider and/or token if missing.
 *
 * @param bool $quiet When true, use auto-detected provider or fail; do not prompt. Token missing fails with error.
 * @return \App\Service\GitProviderInterface|null The provider instance or null if not configured
 */
function _get_git_provider(bool $quiet = false): ?\App\Service\GitProviderInterface
{
    try {
        $gitRepository = _get_git_repository();
        $gitSetupService = _get_git_setup_service();
        $logger = _get_logger();
        $translator = _get_translation_service();

        // Get provider from project config (with auto-detection)
        $providerType = $gitRepository->getGitProvider();
        if ($providerType === null) {
            $providerType = $gitSetupService->ensureGitProviderConfigured(
                io(),
                $quiet
            );
        }

        if (! in_array($providerType, ['github', 'gitlab'], true)) {
            return null;
        }

        $repoOwner = $gitRepository->getRepositoryOwner();
        $repoName = $gitRepository->getRepositoryName();

        if (! $repoOwner || ! $repoName) {
            return null;
        }

        // Get tokens: project config first, then global config fallback
        $projectConfig = $gitRepository->readProjectConfig();
        $globalConfig = _get_git_config();

        // Determine token keys based on provider
        $tokenKey = $providerType === 'github' ? 'githubToken' : 'gitlabToken';
        $globalTokenKey = $providerType === 'github' ? 'GITHUB_TOKEN' : 'GITLAB_TOKEN';

        // Check if token exists
        $token = $projectConfig[$tokenKey] ?? $globalConfig[$globalTokenKey] ?? null;

        // If token is missing, try to ensure it's configured (will prompt if needed, unless quiet)
        if ($token === null || (is_string($token) && trim($token) === '')) {
            $token = $gitSetupService->ensureGitTokenConfigured(
                $providerType,
                io(),
                $globalConfig,
                $quiet
            );

            // If user skipped or token is still null, return null
            if ($token === null || trim($token) === '') {
                // Check if any tokens exist globally to provide helpful message
                $hasAnyGlobalToken = ($globalConfig['GITHUB_TOKEN'] ?? null) !== null
                    || ($globalConfig['GITLAB_TOKEN'] ?? null) !== null;

                if (! $hasAnyGlobalToken) {
                    $logger->error(
                        \App\Service\Logger::VERBOSITY_NORMAL,
                        $translator->trans('config.git_token_no_tokens')
                    );
                }

                return null;
            }
        }

        $token = is_string($token) ? trim($token) : '';

        if ($providerType === 'github') {
            return new GithubProvider(
                $token,
                $repoOwner,
                $repoName
            );
        }

        // GitLab provider
        // Instance URL: project config first, then global config fallback
        $instanceUrl = $projectConfig['gitlabInstanceUrl'] ?? $globalConfig['GITLAB_INSTANCE_URL'] ?? null;

        return new \App\Service\GitLabProvider(
            $token,
            $repoOwner,
            $repoName,
            $instanceUrl
        );
    } catch (\Exception $e) {
        // Config might not exist or provider not configured
        return null;
    }
}

/**
 * @deprecated Use _get_git_provider() instead. This function is kept for backward compatibility.
 */
function _get_github_provider(): ?GithubProvider
{
    $provider = _get_git_provider();

    return $provider instanceof GithubProvider ? $provider : null;
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

function _get_duration_parser(): \App\Service\DurationParser
{
    return new \App\Service\DurationParser();
}

function _get_issue_field_resolver(): \App\Service\IssueFieldResolver
{
    return new \App\Service\IssueFieldResolver(_get_jira_service(), _get_duration_parser());
}

function _get_fields_parser(): \App\Service\FieldsParser
{
    return new \App\Service\FieldsParser(_get_duration_parser());
}

/**
 * Gets the color configuration with theme detection.
 *
 * @return array<string, string>
 */
function _get_colour_config(): array
{
    $colours = require __DIR__ . '/src/config/colours.php';
    $themeDetector = new ThemeDetector();
    $theme = $themeDetector->detect();

    return $colours[$theme] ?? $colours['light'];
}

/**
 * Gets the Logger service instance.
 */
function _get_logger(): Logger
{
    $colors = _get_colour_config();

    return new Logger(io(), $colors);
}

/**
 * Gets the configured base branch for the repository.
 * Auto-detects or prompts user if not configured.
 * Returns branch name with 'origin/' prefix for consistency.
 *
 * @param bool $quiet When true, use DEFAULT_BASE_BRANCH if not configured (and validate it exists); do not prompt
 */
function _get_base_branch(bool $quiet = false): string
{
    static $baseBranchByQuiet = [];
    if (! isset($baseBranchByQuiet[$quiet])) {
        $gitSetupService = _get_git_setup_service();
        $baseBranchByQuiet[$quiet] = $gitSetupService->ensureBaseBranchConfigured(io(), $quiet);
    }

    return $baseBranchByQuiet[$quiet];
}

/**
 * Gets configured base branch from project config without prompting.
 */
function _get_configured_base_branch_or_null(): ?string
{
    try {
        $config = _get_git_repository()->readProjectConfig();
    } catch (\Exception) {
        return null;
    }

    $configured = $config['baseBranch'] ?? null;
    if (! is_string($configured) || trim($configured) === '') {
        return null;
    }

    return str_starts_with($configured, 'origin/') ? $configured : 'origin/' . trim($configured);
}

/**
 * Gets the ErrorResponder instance.
 */
function _get_error_responder(): ErrorResponder
{
    return new ErrorResponder(_get_translation_service(), _get_colour_config(), _get_logger());
}

/**
 * Gets the AgentModeHelper instance (for --agent JSON input/output).
 */
function _get_agent_mode_helper(): AgentModeHelper
{
    return new AgentModeHelper();
}

/**
 * Gets the ColorHelper service instance.
 */
function _get_color_helper(): \App\Service\ColorHelper
{
    static $colorHelper = null;
    if ($colorHelper === null) {
        $colorHelper = new \App\Service\ColorHelper(_get_colour_config());
    }

    return $colorHelper;
}

/**
 * Gets the ResponderHelper service instance (shared presentation helpers for Responders).
 */
function _get_responder_helper(): \App\Service\ResponderHelper
{
    static $responderHelper = null;
    if ($responderHelper === null) {
        $responderHelper = new \App\Service\ResponderHelper(_get_translation_service(), _get_color_helper());
    }

    return $responderHelper;
}

/**
 * Gets the CommentBodyParser service (stateless, no dependencies).
 */
function _get_comment_body_parser(): \App\Service\CommentBodyParser
{
    return new \App\Service\CommentBodyParser();
}

/**
 * Gets a FileSystem instance with Local adapter (for production use).
 */
function _get_file_system(): FileSystem
{
    static $fileSystem = null;
    if ($fileSystem === null) {
        $fileSystem = FileSystem::createLocal();
    }

    return $fileSystem;
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

    // Check 0: Skip version check for config:init command to avoid delays during initialization
    if (isset($_SERVER['argv'])) {
        $argv = $_SERVER['argv'];
        foreach ($argv as $arg) {
            if ($arg === 'config:init' || $arg === 'init') {
                // Running config:init, skip update check to avoid network delays
                return;
            }
        }
    }

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

    // Get GitHub token from config if available (needed for private repositories)
    // UpdateHandler is specifically for GitHub (updating stud-cli itself)
    // Read config file directly to avoid circular dependency with _get_config()
    $gitToken = null;

    try {
        $configPath = _get_config_path();
        if (file_exists($configPath)) {
            $config = Yaml::parseFile($configPath);
            // Try new format first, then fallback to old format for migration compatibility
            $gitToken = $config['GITHUB_TOKEN'] ?? $config['GIT_TOKEN'] ?? null;
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
            _get_file_system(),
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
 * Checks if configuration file exists before command execution.
 * Aborts non-whitelisted commands if config is missing.
 */
#[AsListener(event: ConsoleEvents::COMMAND)]
function _config_check_listener(ConsoleCommandEvent $event): void
{
    // Skip config check during PHAR compilation/build process
    // During castor repack, the execution context is not suitable for this check
    $constantsPath = __DIR__ . '/src/config/constants.php';
    if (! file_exists($constantsPath)) {
        // Constants file doesn't exist (likely during build), skip check
        return;
    }

    $command = $event->getCommand();
    if ($command === null) {
        return;
    }

    // Only check config for stud-cli task commands, not Castor internal commands (e.g., repack)
    // Castor internal commands are not instances of TaskCommand
    if (! $command instanceof \Castor\Console\Command\TaskCommand) {
        // This is a Castor internal command, skip config check
        return;
    }

    $commandName = $command->getName();

    // Whitelist: Commands that should work without config
    $whitelistedCommands = [
        'config:init',
        'config:show',
        'config:project-init',
        'cpi', // alias
        'init', // alias
        'help',
        'main', // default command
        'cache:clear',
        'cc', // alias
    ];

    // Skip check for whitelisted commands
    if (in_array($commandName, $whitelistedCommands, true)) {
        return;
    }

    // Check if config file exists
    $configPath = _get_config_path();
    if (file_exists($configPath)) {
        return;
    }

    // Config file is missing and command is not whitelisted
    // Get translation service (works without config, defaults to 'en')
    $translator = _get_translation_service();
    $io = new SymfonyStyle($event->getInput(), $event->getOutput());
    $logger = new Logger($io, _get_colour_config());

    $logger->warning(Logger::VERBOSITY_NORMAL, $translator->trans('config.error.missing_setup'));
    $logger->text(Logger::VERBOSITY_NORMAL, $translator->trans('config.error.run_init_instruction'));

    // Prevent command execution and set exit code to 1
    $event->disableCommand();
    $command->setCode(function () {
        return 1;
    });
}

/**
 * Checks for required PHP extensions before command execution.
 * Aborts non-whitelisted commands if required extensions are missing.
 */
#[AsListener(event: ConsoleEvents::COMMAND)]
function _php_extension_check_listener(ConsoleCommandEvent $event): void
{
    // Skip extension check during PHAR compilation/build process
    // During castor repack, the execution context is not suitable for this check
    $constantsPath = __DIR__ . '/src/config/constants.php';
    if (! file_exists($constantsPath)) {
        // Constants file doesn't exist (likely during build), skip check
        return;
    }

    $command = $event->getCommand();
    if ($command === null) {
        return;
    }

    // Only check extensions for stud-cli task commands, not Castor internal commands (e.g., repack)
    // Castor internal commands are not instances of TaskCommand
    if (! $command instanceof \Castor\Console\Command\TaskCommand) {
        // This is a Castor internal command, skip extension check
        return;
    }

    $commandName = $command->getName();

    // Whitelist: Commands that should work without extensions (user needs to see help/init)
    $whitelistedCommands = [
        'config:init',
        'config:project-init',
        'cpi', // alias
        'init', // alias
        'help',
        'main', // default command
        'cache:clear',
        'cc', // alias
    ];

    // Skip check for whitelisted commands
    if (in_array($commandName, $whitelistedCommands, true)) {
        return;
    }

    // Check for required PHP extensions
    $missingExtensions = [];
    if (! extension_loaded('xml')) {
        $missingExtensions[] = 'ext-xml';
    }

    // If no extensions are missing, proceed
    if (empty($missingExtensions)) {
        return;
    }

    // Required extensions are missing and command is not whitelisted
    // Get translation service (works without config, defaults to 'en')
    $translator = _get_translation_service();
    $io = new SymfonyStyle($event->getInput(), $event->getOutput());
    $logger = new Logger($io, _get_colour_config());

    $errorMessages = [
        'Required PHP extension is missing: ' . implode(', ', $missingExtensions),
        'Install it using one of the following commands:',
    ];

    // Add installation instructions for each missing extension
    foreach ($missingExtensions as $extension) {
        if ($extension === 'ext-xml') {
            $errorMessages[] = ' Ubuntu/Debian: sudo apt-get install php-xml';
            $errorMessages[] = ' Fedora/RHEL: sudo dnf install php-xml';
            $errorMessages[] = ' macOS (Homebrew): brew install php-xml';
        }
    }

    $errorMessages[] = 'After installation, restart your terminal or web server.';

    $logger->error(Logger::VERBOSITY_NORMAL, $errorMessages);

    // Prevent command execution and set exit code to 1
    $event->disableCommand();
    $command->setCode(function () {
        return 1;
    });
}

/**
 * Config pass listener: Runs migrations and validates configuration before command execution.
 * This listener runs after config check and extension check, ensuring config file exists.
 */
#[AsListener(event: ConsoleEvents::COMMAND)]
function _config_pass_listener(ConsoleCommandEvent $event): void
{
    // Skip during PHAR compilation/build process
    $constantsPath = __DIR__ . '/src/config/constants.php';
    if (! file_exists($constantsPath)) {
        return;
    }

    $command = $event->getCommand();
    if ($command === null) {
        return;
    }

    // Only process stud-cli task commands
    if (! $command instanceof \Castor\Console\Command\TaskCommand) {
        return;
    }

    $commandName = $command->getName();

    // Whitelist: Commands that should work without config pass
    $whitelistedCommands = [
        'config:init',
        'config:show',
        'config:project-init',
        'cpi', // alias
        'init', // alias
        'help',
        'main', // default command
        'cache:clear',
        'cc', // alias
    ];

    if (in_array($commandName, $whitelistedCommands, true)) {
        return;
    }

    // Check if config file exists (should exist at this point due to _config_check_listener)
    $configPath = _get_config_path();
    if (! file_exists($configPath)) {
        return;
    }

    try {
        $io = new SymfonyStyle($event->getInput(), $event->getOutput());
        $logger = new Logger($io, _get_colour_config());
        $translator = _get_translation_service();
        $fileSystem = _get_file_system();

        // Read current config
        $config = Yaml::parseFile($configPath);
        $currentVersion = $config['migration_version'] ?? '0';

        // Step 1: Run global migrations if pending
        $registry = new MigrationRegistry($logger, $translator, $fileSystem);
        $globalMigrations = $registry->discoverGlobalMigrations();
        $pendingGlobalMigrations = $registry->getPendingMigrations($globalMigrations, $currentVersion);

        if (! empty($pendingGlobalMigrations)) {
            $logger->section(Logger::VERBOSITY_NORMAL, $translator->trans('migration.global.running'));
            $executor = new MigrationExecutor($logger, $fileSystem, $translator);
            $config = $executor->executeMigrations($pendingGlobalMigrations, $config, $configPath);
            $logger->success(Logger::VERBOSITY_NORMAL, $translator->trans('migration.global.complete'));
            $currentVersion = $config['migration_version'] ?? $currentVersion;
        }

        // Step 2: Run project migrations if in git repository
        try {
            $gitRepository = _get_git_repository();
            $projectConfigPath = $gitRepository->getProjectConfigPath();

            if (file_exists($projectConfigPath)) {
                $projectConfig = Yaml::parseFile($projectConfigPath);
                $projectCurrentVersion = $projectConfig['migration_version'] ?? '0';

                $projectMigrations = $registry->discoverProjectMigrations();
                $pendingProjectMigrations = $registry->getPendingMigrations($projectMigrations, $projectCurrentVersion);

                if (! empty($pendingProjectMigrations)) {
                    $logger->section(Logger::VERBOSITY_NORMAL, $translator->trans('migration.project.running'));
                    $executor = new MigrationExecutor($logger, $fileSystem, $translator);
                    $projectConfig = $executor->executeMigrations($pendingProjectMigrations, $projectConfig, $projectConfigPath);
                    $logger->success(Logger::VERBOSITY_NORMAL, $translator->trans('migration.project.complete'));
                }
            }
        } catch (\RuntimeException $e) {
            // Not in a git repository, skip project migrations
        }

        // Step 3: Validate command requirements
        $validator = new ConfigValidator($logger, $translator, _get_git_branch_service());
        $projectConfig = null;

        try {
            $gitRepository = _get_git_repository();
            $projectConfig = $gitRepository->readProjectConfig();
        } catch (\RuntimeException $e) {
            // Not in a git repository
        }

        $validationResult = $validator->validateCommandRequirements($commandName, $config, $projectConfig);

        // Step 4: Prompt for missing mandatory keys
        if ($validationResult->hasMissingKeys()) {
            // Check if we're in non-interactive mode (TTY not attached or --quiet)
            $input = $event->getInput();
            $isInteractive = $input->isInteractive();
            $isQuiet = $input->hasOption('quiet') && $input->getOption('quiet');

            if (! $isInteractive || $isQuiet) {
                // Non-interactive mode: exit with error
                $missingKeys = array_merge($validationResult->missingGlobalKeys, $validationResult->missingProjectKeys);
                $logger->error(Logger::VERBOSITY_NORMAL, [
                    'Missing required configuration keys: ' . implode(', ', $missingKeys),
                    'Please run "stud config:init" to configure these keys, or run the command without --quiet in interactive mode.',
                ]);
                $event->disableCommand();
                $command->setCode(function () {
                    return 1;
                });

                return;
            }

            // Interactive mode: prompt for missing keys
            if (! empty($validationResult->missingGlobalKeys)) {
                $promptedValues = $validator->promptForMissingKeys($validationResult->missingGlobalKeys, 'global');
                foreach ($promptedValues as $key => $value) {
                    $config[$key] = $value;
                }
                // Save updated global config
                $fileSystem->dumpFile($configPath, $config);
            }

            if (! empty($validationResult->missingProjectKeys)) {
                try {
                    $gitRepository = _get_git_repository();
                    $projectConfigPath = $gitRepository->getProjectConfigPath();
                    $projectConfig = $gitRepository->readProjectConfig();

                    $promptedValues = $validator->promptForMissingKeys($validationResult->missingProjectKeys, 'project');
                    foreach ($promptedValues as $key => $value) {
                        $projectConfig[$key] = $value;
                    }
                    // Save updated project config
                    $gitRepository->writeProjectConfig($projectConfig);
                } catch (\RuntimeException $e) {
                    // Not in a git repository, can't save project config
                    $logger->error(Logger::VERBOSITY_NORMAL, 'Cannot save project configuration: not in a git repository.');
                    $event->disableCommand();
                    $command->setCode(function () {
                        return 1;
                    });

                    return;
                }
            }
        }
    } catch (\Throwable $e) {
        // Fail gracefully - don't block command execution if migration/validation fails
        // Log error but continue
        if (isset($logger)) {
            $logger->error(Logger::VERBOSITY_VERBOSE, ['Config pass error: ' . $e->getMessage()]);
        }
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
    $logger = new Logger($io, _get_colour_config());
    $logger->warning(Logger::VERBOSITY_NORMAL, sprintf(
        "A new version (v%s) is available. Run 'stud up' to update.",
        $latestVersion
    ));
}

// =================================================================================
// Configuration Command
// =================================================================================

#[AsTask(name: 'config:init', aliases: ['init'], description: 'Interactive wizard to set up Jira & Git connection details')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Confirmation message')]
function config_init(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
    }
    $handler = new InitHandler(
        _get_file_system(),
        _get_config_path(),
        _get_translation_service(),
        _get_logger(),
        new \App\Service\GitTokenPromptResolver(),
        _get_init_project_config_follow_up_service()
    );
    $handler->handle(io(), $agent, input()->isInteractive());
    if ($format === OutputFormat::Json) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondSuccess('Configuration initialized'));

        return;
    }
}

#[AsTask(name: 'config:show', description: 'Display current configuration (global and project) with secrets redacted; safe for sharing with support')]
#[AgentOutput(responseClass: \App\Response\ConfigShowResponse::class, description: 'Global and project configuration or a single key value')]
function config_show(
    #[AsOption(name: 'key', shortcut: 'k', description: 'Show only this config key (whitelisted non-secret keys only)')]
    ?string $key = null,
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'With --key: output only the raw value (no section/labels)')]
    bool $quiet = false,
    #[AsOption(name: 'agent', description: 'JSON input (stdin or file path) and JSON output')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON file when using --agent (omit to read from stdin)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $key = isset($input['key']) && $input['key'] !== '' ? (string) $input['key'] : null;
        $quiet = (bool) ($input['quiet'] ?? false);
    }
    $gitRepository = null;

    try {
        $gitRepository = _get_git_repository();
    } catch (\RuntimeException) {
    }
    $handler = new ConfigShowHandler(_get_file_system(), _get_config_path(), $gitRepository);
    $response = $handler->handle($key);
    $quietEffective = $key !== null && $quiet;
    $responder = new ConfigShowResponder(_get_responder_helper(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $quietEffective, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        exit(1);
    }
}

/**
 * Write agent payload to stdout and exit. Uses helper's writeAgentOutput (returns line when no io) then echo and exit.
 *
 * @param array{success: bool, error?: string, data?: mixed} $payload
 */
function _agent_output_and_exit(AgentModeHelper $helper, array $payload): void
{
    $line = $helper->writeAgentOutput($payload);
    if ($line !== null) {
        echo $line;
        exit($helper->exitCodeForPayload($payload));
    }

    return;
}

/**
 * Read and decode agent JSON input. On failure, outputs the error and returns null.
 *
 * @return array<string, mixed>|null null when input is invalid (error already sent to agent output)
 */
function _read_agent_input(?string $inputFile): ?array
{
    $helper = _get_agent_mode_helper();

    try {
        return $helper->readAgentInput($inputFile);
    } catch (AgentModeException $e) {
        _agent_output_and_exit($helper, $helper->buildErrorPayload($e->getMessage()));

        return null;
    }
}

/**
 * Agent-only: when submit JSON has stageAll true, run the same commit + origin push path as stud push before PR creation.
 *
 * @param array<string, mixed> $input Agent JSON (uses isNew, message, pleaseFallback when present)
 */
function _agent_submit_run_push_phase(\Symfony\Component\Console\Style\SymfonyStyle $io, array $input): int
{
    $isNew = (bool) ($input['isNew'] ?? false);
    $message = isset($input['message']) && is_string($input['message']) ? $input['message'] : null;
    $pleaseFallback = array_key_exists('pleaseFallback', $input) ? (bool) $input['pleaseFallback'] : true;
    $gitRepository = _get_git_repository();
    $commitHandler = new CommitHandler($gitRepository, _get_jira_service(), _get_base_branch(), _get_translation_service(), _get_logger());
    $pleaseHandler = new PleaseHandler($gitRepository, _get_translation_service(), _get_logger());
    $pushHandler = new PushHandler($commitHandler, $gitRepository, $pleaseHandler, _get_translation_service(), _get_logger());

    return $pushHandler->handle($io, $isNew, $message, true, true, false, true, $pleaseFallback);
}

/**
 * Write an AgentJsonResponse to stdout and exit.
 */
function _agent_respond(AgentJsonResponse $agentResponse): void
{
    _agent_output_and_exit(_get_agent_mode_helper(), $agentResponse->toPayload());
}

#[AsTask(name: 'config:validate', description: 'Validate that configuration is present and that Jira and the Git provider are reachable')]
#[AgentOutput(responseClass: \App\Response\ConfigValidateResponse::class, description: 'Jira and Git provider connectivity status')]
function config_validate(
    #[AsOption(name: 'skip-jira', description: 'Skip the Jira connectivity check')]
    bool $skipJira = false,
    #[AsOption(name: 'skip-git', description: 'Skip the Git provider connectivity check')]
    bool $skipGit = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $skipJira = (bool) ($input['skipJira'] ?? false);
        $skipGit = (bool) ($input['skipGit'] ?? false);
    }
    _get_config();

    $jiraService = $skipJira ? null : _get_jira_service();
    $gitProvider = null;
    $skipGitForHandler = $skipGit;

    if (! $skipGit) {
        try {
            $gitRepository = _get_git_repository();
            $gitRepository->getProjectConfigPath();
        } catch (\RuntimeException) {
            $skipGitForHandler = true;
        }
        if (! $skipGitForHandler) {
            $gitProvider = _get_git_provider();
            if ($format === OutputFormat::Cli && $gitProvider === null) {
                $translator = _get_translation_service();
                _get_logger()->error(Logger::VERBOSITY_NORMAL, $translator->trans('config.git_provider_not_configured'));
                exit(1);
            }
        }
    }

    $handler = new ConfigValidateHandler($jiraService, $gitProvider, $skipJira, $skipGitForHandler);
    $response = $handler->handle();
    $responder = new ConfigValidateResponder(_get_responder_helper(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        exit(1);
    }
}

#[AsTask(name: 'config:project-init', aliases: ['cpi'], description: 'Create or merge project stud config (.git/stud.config); flags, agent JSON, or interactive when no values are given')]
#[AgentOutput(responseClass: \App\Response\ConfigProjectInitResponse::class, description: 'Merged project configuration (redacted) and whether the file was updated')]
function config_project_init(
    #[AsOption(name: 'project-key', description: 'Jira project key (transition cache)')]
    ?string $projectKey = null,
    #[AsOption(name: 'transition-id', description: 'Jira transition ID to cache')]
    ?int $transitionId = null,
    #[AsOption(name: 'base-branch', description: 'Repository base branch (validated on origin unless skipped)')]
    ?string $baseBranch = null,
    #[AsOption(name: 'git-provider', description: 'github or gitlab')]
    ?string $gitProvider = null,
    #[AsOption(name: 'github-token', description: 'GitHub PAT for this repository')]
    ?string $githubToken = null,
    #[AsOption(name: 'gitlab-token', description: 'GitLab PAT for this repository')]
    ?string $gitlabToken = null,
    #[AsOption(name: 'gitlab-instance-url', description: 'Self-hosted GitLab instance URL')]
    ?string $gitlabInstanceUrl = null,
    #[AsOption(name: 'jira-default-project', description: 'Default Jira project for stud items:create')]
    ?string $jiraDefaultProject = null,
    #[AsOption(name: 'confluence-default-space', description: 'Default Confluence space key')]
    ?string $confluenceDefaultSpace = null,
    #[AsOption(name: 'skip-base-branch-remote-check', description: 'Skip verifying base-branch on origin')]
    bool $skipBaseBranchRemoteCheck = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    $rawAgentInput = [];
    $skipRemote = $skipBaseBranchRemoteCheck;

    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $rawAgentInput = $input;
        $skipRemote = (bool) ($input['skipBaseBranchRemoteCheck'] ?? false);
    }

    $cliPatches = [];
    if (! $agent) {
        if ($projectKey !== null && trim($projectKey) !== '') {
            $cliPatches['projectKey'] = trim($projectKey);
        }
        if ($transitionId !== null) {
            $cliPatches['transitionId'] = $transitionId;
        }
        if ($baseBranch !== null && trim($baseBranch) !== '') {
            $cliPatches['baseBranch'] = trim($baseBranch);
        }
        if ($gitProvider !== null && trim($gitProvider) !== '') {
            $cliPatches['gitProvider'] = strtolower(trim($gitProvider));
        }
        if ($githubToken !== null && trim($githubToken) !== '') {
            $cliPatches['githubToken'] = trim($githubToken);
        }
        if ($gitlabToken !== null && trim($gitlabToken) !== '') {
            $cliPatches['gitlabToken'] = trim($gitlabToken);
        }
        if ($gitlabInstanceUrl !== null && trim($gitlabInstanceUrl) !== '') {
            $cliPatches['gitlabInstanceUrl'] = trim($gitlabInstanceUrl);
        }
        if ($jiraDefaultProject !== null && trim($jiraDefaultProject) !== '') {
            $cliPatches['jiraDefaultProject'] = trim($jiraDefaultProject);
        }
        if ($confluenceDefaultSpace !== null && trim($confluenceDefaultSpace) !== '') {
            $cliPatches['confluenceDefaultSpace'] = trim($confluenceDefaultSpace);
        }
    }

    $hasExplicitCli = $cliPatches !== [] || ($skipBaseBranchRemoteCheck && ! $agent);
    $interactive = ! $agent && ! $hasExplicitCli;

    $gitRepository = _get_git_repository();
    $gitSetup = _get_git_setup_service();
    $promptCollector = new ConfigProjectInitPromptCollector(
        $gitRepository,
        $gitSetup,
        _get_translation_service(),
        _get_logger(),
        new \App\Service\GitTokenPromptResolver()
    );
    $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $promptCollector);
    $response = $handler->handle($rawAgentInput, $cliPatches, $skipRemote, $interactive, $agent);
    $responder = new ConfigProjectInitResponder(_get_responder_helper(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        exit(1);
    }
}

// =================================================================================
// "Noun" Commands (Jira Info)
// =================================================================================

#[AsTask(name: 'projects:list', aliases: ['pj'], description: 'Lists all visible Jira projects')]
#[AgentOutput(responseClass: \App\Response\ProjectListResponse::class, description: 'List of Jira projects')]
function projects_list(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;

    $handler = new ProjectListHandler(_get_jira_service());
    $response = $handler->handle();
    $responder = new ProjectListResponder(_get_responder_helper(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'filters:list', aliases: ['fl'], description: 'Lists all available Jira filters')]
#[AgentOutput(responseClass: \App\Response\FilterListResponse::class, description: 'List of Jira filters')]
function filters_list(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;

    $handler = new FilterListHandler(_get_jira_service(), _get_translation_service());
    $response = $handler->handle();
    $responder = new FilterListResponder(_get_responder_helper(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'items:list', aliases: ['ls'], description: 'Lists active work items (your dashboard)')]
#[AgentOutput(responseClass: \App\Response\ItemListResponse::class, description: 'List of work items')]
function items_list(
    #[AsOption(name: 'all', shortcut: 'a', description: 'List items for all users')]
    bool $all = false,
    #[AsOption(name: 'project', shortcut: 'p', description: 'Filter by project key')]
    ?string $project = null,
    #[AsOption(name: 'sort', shortcut: 's', description: 'Sort results by Key or Status')]
    ?string $sort = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $all = (bool) ($input['all'] ?? false);
        $project = isset($input['project']) ? (string) $input['project'] : null;
        $sort = isset($input['sort']) ? ucfirst(strtolower((string) $input['sort'])) : null;
    } else {
        $translator = _get_translation_service();
        if ($sort !== null) {
            $normalizedSort = strtolower($sort);
            if (! in_array($normalizedSort, ['key', 'status'], true)) {
                _get_logger()->error(Logger::VERBOSITY_NORMAL, $translator->trans('item.list.error_invalid_sort', ['value' => $sort]));
                exit(1);
            }
            $sort = ucfirst($normalizedSort);
        }
    }

    $handler = new ItemListHandler(_get_jira_service());
    $response = $handler->handle($all, $project, $sort);
    $responder = new ItemListResponder(_get_responder_helper(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'items:search', aliases: ['search'], description: 'Search for issues using JQL')]
#[AgentOutput(responseClass: \App\Response\SearchResponse::class, description: 'JQL search results')]
function items_search(
    #[AsArgument(name: 'jql', description: 'The JQL query string (or inputFile when --agent)')]
    ?string $jql = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    if ($agent) {
        $input = _read_agent_input($jql);
        if ($input === null) {
            return;
        }
        $jql = (string) ($input['jql'] ?? '');
    } elseif ($jql === null || $jql === '') {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, 'The "jql" argument is required.');
        exit(1);
    }
    $handler = new SearchHandler(_get_jira_service());
    $response = $handler->handle($jql);
    $responder = new SearchResponder(_get_responder_helper(), _get_jira_config(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'filters:show', aliases: ['fs'], description: 'Retrieve issues from a saved Jira filter')]
#[AgentOutput(responseClass: \App\Response\FilterShowResponse::class, description: 'Issues from a saved filter')]
function filters_show(
    #[AsArgument(name: 'filterName', description: 'The filter name (or inputFile when --agent)')]
    ?string $filterName = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    if ($agent) {
        $input = _read_agent_input($filterName);
        if ($input === null) {
            return;
        }
        $filterName = (string) ($input['filterName'] ?? '');
    } elseif ($filterName === null || $filterName === '') {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, 'The "filterName" argument is required.');
        exit(1);
    }
    $handler = new FilterShowHandler(_get_jira_service());
    $response = $handler->handle($filterName);
    $responder = new FilterShowResponder(_get_responder_helper(), _get_jira_config(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'items:show', aliases: ['sh'], description: 'Shows detailed info for one work item')]
#[AgentOutput(responseClass: \App\Response\ItemShowResponse::class, description: 'Detailed work item information')]
function items_show(
    #[AsArgument(name: 'key', description: 'The Jira issue key (or inputFile when --agent)')]
    ?string $key = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    if ($agent) {
        $input = _read_agent_input($key);
        if ($input === null) {
            return;
        }
        $key = (string) ($input['key'] ?? '');
    } elseif ($key === null || $key === '') {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, 'The "key" argument is required.');
        exit(1);
    }
    $handler = new ItemShowHandler(_get_jira_service());
    $response = $handler->handle($key);
    $responder = new ItemShowResponder(_get_responder_helper(), _get_jira_config(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $key, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
    }
}

#[AsTask(name: 'items:download', aliases: ['idl'], description: 'Downloads Jira issue attachments or a single attachment URL')]
#[AgentOutput(responseClass: \App\Response\ItemDownloadResponse::class, description: 'Downloaded attachment paths (filename, path) and per-file errors')]
function items_download(
    #[AsArgument(name: 'key', description: 'Jira issue key (optional if --url); downloads all attachments and ignores --url when set (or inputFile when --agent)')]
    ?string $key = null,
    #[AsOption(name: 'url', description: 'Jira attachment content URL when no issue key is given')]
    ?string $url = null,
    #[AsOption(name: 'path', description: 'Target directory under cwd (created if missing; default: .cursor/stud-downloads)')]
    ?string $path = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    if ($agent) {
        $input = _read_agent_input($key);
        if ($input === null) {
            return;
        }
        $issueKey = (string) ($input['issueKey'] ?? $input['key'] ?? '');
        $key = $issueKey !== '' ? $issueKey : null;
        $urlRaw = $input['url'] ?? null;
        $url = is_string($urlRaw) ? $urlRaw : null;
        $pathRaw = $input['path'] ?? null;
        $path = is_string($pathRaw) ? $pathRaw : null;
    }
    $handler = new ItemDownloadHandler(_get_file_system(), _get_jira_attachment_service(), _get_translation_service());
    $response = $handler->handle($key, $url, $path);
    $responder = new ItemDownloadResponder(_get_responder_helper(), _get_jira_config(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

/**
 * @param list<string> $files
 */
#[AsTask(name: 'items:upload', aliases: ['iup'], description: 'Uploads local files as attachments on a Jira issue')]
#[AgentOutput(responseClass: \App\Response\ItemUploadResponse::class, description: 'Uploaded attachment paths (filename, path) and per-file errors')]
function items_upload(
    #[AsArgument(name: 'key', description: 'The Jira issue key (or inputFile when --agent)')]
    ?string $key = null,
    #[AsOption(name: 'file', shortcut: 'f', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Local file path relative to cwd (repeat for multiple files)')]
    array $files = [],
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    $translator = _get_translation_service();
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $key = isset($input['key']) ? (string) $input['key'] : $key;
        $files = [];
        $rawFiles = $input['files'] ?? null;
        if (is_array($rawFiles)) {
            foreach ($rawFiles as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $files[] = trim($item);
                }
            }
        }
    }
    if ($key === null || trim($key) === '') {
        if ($format === OutputFormat::Json) {
            _agent_respond(new AgentJsonResponse(false, error: $translator->trans('item.upload.error_no_key')));

            return;
        }
        _get_logger()->error(Logger::VERBOSITY_NORMAL, $translator->trans('item.upload.error_no_key'));
        exit(1);
    }
    if ($files === []) {
        if ($format === OutputFormat::Json) {
            _agent_respond(new AgentJsonResponse(false, error: $translator->trans('item.upload.error_no_files')));

            return;
        }
        _get_logger()->error(Logger::VERBOSITY_NORMAL, $translator->trans('item.upload.error_no_files'));
        exit(1);
    }
    $handler = new ItemUploadHandler(_get_file_system(), _get_jira_attachment_service(), $translator);
    $inputDto = new ItemUploadInput(trim($key), $files);
    $response = $handler->handle($inputDto);
    $responder = new ItemUploadResponder(_get_responder_helper(), _get_jira_config(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

/**
 * Normalizes summary for items:create: trim cast, and fallback to argv when option value is missing (e.g. Castor binding).
 *
 * @return string|null
 */
function _items_create_normalize_summary(?string $summary): ?string
{
    if ($summary !== null && trim($summary) !== '') {
        return trim($summary);
    }
    if (! isset($_SERVER['argv']) || ! is_array($_SERVER['argv'])) {
        return null;
    }
    $argv = $_SERVER['argv'];
    foreach ($argv as $i => $arg) {
        if ($arg === '-m' || $arg === '--summary') {
            $next = $argv[$i + 1] ?? null;
            if ($next !== null && $next !== '' && ! str_starts_with($next, '-')) {
                return trim($next);
            }

            break;
        }
        if (str_starts_with($arg, '--summary=')) {
            return trim(substr($arg, 10));
        }
    }

    return null;
}

#[AsTask(name: 'items:create', aliases: ['ic'], description: 'Creates a Jira issue in a project')]
#[AgentOutput(responseClass: \App\Response\ItemCreateResponse::class, description: 'Created issue key and URL')]
function items_create(
    #[AsOption(name: 'project', shortcut: 'p', description: 'Jira project key (or set JIRA_DEFAULT_PROJECT in .git/stud.config)')]
    ?string $project = null,
    #[AsOption(name: 'type', shortcut: 't', description: 'Issue type (default: Story)')]
    ?string $type = null,
    #[AsOption(name: 'summary', shortcut: 'm', description: 'Issue summary/title')]
    ?string $summary = null,
    #[AsOption(name: 'description', shortcut: 'd', description: 'Issue description (STDIN takes precedence when piping)')]
    ?string $description = null,
    #[AsOption(name: 'description-format', description: 'Description format: plain (default) or markdown')]
    ?string $descriptionFormat = null,
    #[AsOption(name: 'parent', description: 'Parent issue key for creating a sub-task')]
    ?string $parent = null,
    #[AsOption(name: 'fields', shortcut: 'F', description: 'Extra fields as key=value pairs separated by semicolons (e.g. "labels=Bug,DX;priority=High")')]
    ?string $fields = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    $interactive = ! $agent && function_exists('posix_isatty') && @posix_isatty(STDIN);
    /** @var array<string, string|list<string>>|null $fieldsMap */
    $fieldsMap = null;
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $project = $input['project'] ?? null;
        $type = $input['type'] ?? null;
        $summary = $input['summary'] ?? null;
        $description = $input['description'] ?? null;
        $descriptionFormat = $input['descriptionFormat'] ?? null;
        $parent = $input['parent'] ?? null;
        if (isset($input['fields'])) {
            if (is_array($input['fields'])) {
                $fieldsMap = $input['fields'];
            } else {
                $fields = is_string($input['fields']) ? $input['fields'] : null;
            }
        }
    } else {
        $summary = _items_create_normalize_summary($summary);
    }
    $handler = new ItemCreateHandler(_get_git_repository(), _get_jira_service(), _get_translation_service(), _get_issue_field_resolver(), _get_fields_parser());
    $input = new ItemCreateInput($project, $type, $summary, $description, $descriptionFormat, $parent, $fields, $fieldsMap);
    $response = $handler->handle(io(), $interactive, $input);
    $responder = new ItemCreateResponder(_get_translation_service(), _get_jira_config(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'items:update', aliases: ['iu'], description: 'Update a Jira issue fields')]
#[AgentOutput(responseClass: \App\Response\ItemUpdateResponse::class, description: 'Updated issue key')]
function items_update(
    #[AsArgument(name: 'key', description: 'The Jira issue key (or inputFile when --agent)')]
    ?string $key = null,
    #[AsOption(name: 'summary', shortcut: 'm', description: 'Update the issue summary/title')]
    ?string $summary = null,
    #[AsOption(name: 'description', shortcut: 'd', description: 'Update the issue description (STDIN takes precedence when piping)')]
    ?string $description = null,
    #[AsOption(name: 'description-format', description: 'Description format: plain (default) or markdown')]
    ?string $descriptionFormat = null,
    #[AsOption(name: 'fields', shortcut: 'F', description: 'Extra fields as key=value pairs separated by semicolons (e.g. "labels=Bug,DX;priority=High")')]
    ?string $fields = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    /** @var array<string, string|list<string>>|null $fieldsMap */
    $fieldsMap = null;
    if ($agent) {
        // Read from explicit input file only; otherwise stdin. Using $key as input source would treat
        // a Jira key (e.g. SCI-79) as a file path and prevent JSON (including fields) from being read.
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $key = isset($input['key']) ? (string) $input['key'] : $key;
        $summary = $input['summary'] ?? null;
        $description = $input['description'] ?? null;
        $descriptionFormat = $input['descriptionFormat'] ?? null;
        if (isset($input['fields'])) {
            if (is_array($input['fields'])) {
                $fieldsMap = $input['fields'];
            } else {
                $fields = is_string($input['fields']) ? $input['fields'] : null;
            }
        }
    }
    if ($key === null || trim($key) === '') {
        $translator = _get_translation_service();
        if ($format === OutputFormat::Json) {
            _agent_respond(new AgentJsonResponse(false, error: $translator->trans('item.update.error_no_key')));

            return;
        }
        _get_logger()->error(Logger::VERBOSITY_NORMAL, $translator->trans('item.update.error_no_key'));
        exit(1);
    }
    $handler = new ItemUpdateHandler(_get_jira_service(), _get_translation_service(), _get_fields_parser());
    $input = new ItemUpdateInput(trim($key), $summary, $description, $descriptionFormat, $fields, $fieldsMap);
    $response = $handler->handle($input);
    $responder = new ItemUpdateResponder(_get_translation_service(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'items:transition', aliases: ['tx'], description: 'Transitions a Jira work item to a different status')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Transition result')]
function items_transition(
    #[AsArgument(name: 'key', description: 'The Jira issue key (or inputFile when --agent). Optional - will detect from branch if not provided')]
    ?string $key = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    if ($agent) {
        $input = _read_agent_input($key);
        if ($input === null) {
            return;
        }
        $key = isset($input['key']) ? (string) $input['key'] : null;
    }
    $handler = new ItemTransitionHandler(_get_git_repository(), _get_jira_service(), _get_translation_service(), _get_logger());
    $exitCode = $handler->handle(io(), $key);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Transition completed', 'Transition failed'));

        return;
    }
    exit($exitCode);
}

// =================================================================================
// "Verb" Commands (Git Workflow)
// =================================================================================

#[AsTask(name: 'items:start', aliases: ['start'], description: 'Creates a new git branch from a Jira item')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Branch creation result')]
function items_start(
    #[AsArgument(name: 'key', description: 'The Jira issue key (or inputFile when --agent)')]
    ?string $key = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    if ($agent) {
        $input = _read_agent_input($key);
        if ($input === null) {
            return;
        }
        $key = (string) ($input['key'] ?? '');
    } elseif ($key === null || $key === '') {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, 'The "key" argument is required.');
        exit(1);
    }
    $handler = new ItemStartHandler(_get_git_repository(), _get_git_branch_service(), _get_jira_service(), _get_base_branch(), _get_translation_service(), _get_jira_config(), _get_logger());
    $exitCode = $handler->handle(io(), $key);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Branch created', 'Start failed'));

        return;
    }
}

#[AsTask(name: 'items:takeover', aliases: ['to'], description: 'Takes over an issue from another user')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Takeover result')]
function items_takeover(
    #[AsArgument(name: 'key', description: 'The Jira issue key (or inputFile when --agent)')]
    ?string $key = null,
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'Non-interactive: use defaults, no prompts')]
    bool $quiet = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    if ($agent) {
        $input = _read_agent_input($key);
        if ($input === null) {
            return;
        }
        $key = (string) ($input['key'] ?? '');
        $quiet = true; // agent mode is non-interactive; no need to read from input
    } elseif ($key === null || $key === '') {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, 'The "key" argument is required.');
        exit(1);
    }
    $baseBranch = _get_base_branch();
    $gitBranchService = _get_git_branch_service();
    $itemStartHandler = new ItemStartHandler(_get_git_repository(), $gitBranchService, _get_jira_service(), $baseBranch, _get_translation_service(), _get_jira_config(), _get_logger());
    $handler = new ItemTakeoverHandler(_get_git_repository(), $gitBranchService, _get_jira_service(), $itemStartHandler, $baseBranch, _get_translation_service(), _get_jira_config(), _get_logger());
    $exitCode = $handler->handle(io(), $key, $quiet);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Takeover completed', 'Takeover failed'));

        return;
    }
    exit($exitCode);
}

#[AsTask(name: 'branch:rename', aliases: ['rn'], description: 'Renames a branch, optionally regenerating name from Jira issue')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Rename result')]
function branch_rename(
    #[AsArgument(name: 'branch', description: 'The branch to rename (or inputFile when --agent; defaults to current branch)')]
    ?string $branch = null,
    #[AsArgument(name: 'key', description: 'The Jira issue key to regenerate branch name from (e.g., PROJ-123)')]
    ?string $key = null,
    #[AsOption(name: 'name', shortcut: 'N', description: 'Explicit new branch name (no prefix will be added)')]
    ?string $explicitName = null,
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'Non-interactive: use defaults, no prompts')]
    bool $quiet = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    if ($agent) {
        $input = _read_agent_input($branch);
        if ($input === null) {
            return;
        }
        $branch = $input['branch'] ?? null;
        $key = $input['key'] ?? null;
        $explicitName = $input['explicitName'] ?? null;
        $quiet = true; // agent mode is non-interactive; no need to read from input
    }
    $gitRepository = _get_git_repository();
    $gitProvider = _get_git_provider();
    $handler = new BranchRenameHandler($gitRepository, _get_git_branch_service(), _get_jira_service(), $gitProvider, _get_translation_service(), _get_jira_config(), _get_base_branch(), _get_logger(), _get_html_converter());
    $exitCode = $handler->handle(io(), $branch, $key, $explicitName, $quiet);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Branch renamed', 'Rename failed'));

        return;
    }
    exit($exitCode);
}

#[AsTask(name: 'branches:list', aliases: ['bl'], description: 'List branches with status and auto-clean eligibility')]
#[AgentOutput(responseClass: \App\Response\BranchListResponse::class, description: 'List of branches with status')]
function branches_list(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): int {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;

    $gitRepository = _get_git_repository();
    $gitBranchService = _get_git_branch_service();
    $githubProvider = _get_github_provider();
    $resolver = new \App\Service\BranchDeletionEligibilityResolver($gitRepository, $gitBranchService, $githubProvider);
    $handler = new BranchListHandler($gitRepository, $gitBranchService, $resolver, _get_configured_base_branch_or_null(), _get_translation_service());
    $response = $handler->handle();
    $responder = new BranchListResponder(_get_responder_helper(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return 0;
    }

    return 0;
}

#[AsTask(name: 'branches:clean', aliases: ['bc'], description: 'Clean branches with conservative auto-clean eligibility')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Cleanup result')]
function branches_clean(
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'Non-interactive: use defaults, no prompts')]
    bool $quiet = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): int {
    _load_constants();
    if ($agent) {
        $quiet = true;
    }
    $gitRepository = _get_git_repository();
    $gitBranchService = _get_git_branch_service();
    $gitProvider = _get_git_provider();
    $resolver = new \App\Service\BranchDeletionEligibilityResolver($gitRepository, $gitBranchService, $gitProvider);
    $handler = new BranchCleanHandler($gitRepository, $gitBranchService, $resolver, _get_configured_base_branch_or_null(), _get_translation_service(), _get_logger());
    $exitCode = $handler->handle(io(), $quiet);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Branches cleaned', 'Clean failed'));

        return 0;
    }

    return $exitCode;
}

#[AsTask(name: 'commit', aliases: ['co'], description: 'Guides you through making a conventional commit')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Commit result')]
function commit(
    #[AsOption(name: 'new', description: 'Create a new logical commit instead of a fixup')]
    bool $isNew = false,
    #[AsOption(name: 'message', shortcut: 'm', description: 'Provide a commit message to bypass the prompter')]
    ?string $message = null,
    #[AsOption(name: 'all', shortcut: 'a', description: 'Stage all changes before committing')]
    bool $stageAll = false,
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'Non-interactive: use defaults, no prompts')]
    bool $quiet = false,
    #[AsOption(name: 'help', shortcut: 'h', description: 'Display help for this command')]
    bool $help = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $isNew = (bool) ($input['isNew'] ?? false);
        $message = $input['message'] ?? null;
        $stageAll = (bool) ($input['stageAll'] ?? false);
        $quiet = true;
    }
    if (! $agent && $help) {
        $helpService = new \App\Service\HelpService(_get_translation_service(), _get_file_system());
        $helpService->displayCommandHelp(_get_logger(), 'commit');

        return;
    }
    $handler = new CommitHandler(_get_git_repository(), _get_jira_service(), _get_base_branch(), _get_translation_service(), _get_logger());
    $exitCode = $handler->handle(io(), $isNew, $message, $stageAll, $quiet);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Commit created', 'Commit failed'));

        return;
    }
}

#[AsTask(name: 'push', aliases: ['ps'], description: 'Commit (like stud commit) then push to origin; optional stud please after a failed push')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Push result')]
function push(
    #[AsOption(name: 'new', description: 'Create a new logical commit instead of a fixup')]
    bool $isNew = false,
    #[AsOption(name: 'message', shortcut: 'm', description: 'Provide a commit message to bypass the prompter')]
    ?string $message = null,
    #[AsOption(name: 'all', shortcut: 'a', description: 'Stage all changes before committing')]
    bool $stageAll = false,
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'Non-interactive: no prompts; failed push runs stud please unless --no-please')]
    bool $quiet = false,
    #[AsOption(name: 'no-please', description: 'After a failed normal push, do not run or prompt for stud please')]
    bool $noPlease = false,
    #[AsOption(name: 'help', shortcut: 'h', description: 'Display help for this command')]
    bool $help = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $pleaseFallback = true;
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $isNew = (bool) ($input['isNew'] ?? false);
        $message = $input['message'] ?? null;
        $stageAll = (bool) ($input['stageAll'] ?? false);
        $quiet = true;
        $pleaseFallback = array_key_exists('pleaseFallback', $input) ? (bool) $input['pleaseFallback'] : true;
        // CLI --no-please with --agent maps to pleaseFallback false (agent JSON uses pleaseFallback only).
        if ($noPlease) {
            $pleaseFallback = false;
        }
    }
    if (! $agent && $help) {
        $helpService = new \App\Service\HelpService(_get_translation_service(), _get_file_system());
        $helpService->displayCommandHelp(_get_logger(), 'push');

        return;
    }
    $gitRepository = _get_git_repository();
    $commitHandler = new CommitHandler($gitRepository, _get_jira_service(), _get_base_branch(), _get_translation_service(), _get_logger());
    $pleaseHandler = new PleaseHandler($gitRepository, _get_translation_service(), _get_logger());
    $handler = new PushHandler($commitHandler, $gitRepository, $pleaseHandler, _get_translation_service(), _get_logger());
    $noPleaseForHandler = $agent ? false : $noPlease;
    $exitCode = $handler->handle(io(), $isNew, $message, $stageAll, $quiet, $noPleaseForHandler, $agent, $pleaseFallback);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Push completed', 'Push failed'));

        return;
    }
}

#[AsTask(name: 'please', aliases: ['pl'], description: 'A power-user, safe force-push (force-with-lease)')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Force push result')]
function please(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $handler = new PleaseHandler(_get_git_repository(), _get_translation_service(), _get_logger());
    $exitCode = $handler->handle(io());
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Force push completed', 'Force push failed'));

        return;
    }
}

#[AsTask(name: 'commit:undo', aliases: ['undo'], description: 'Remove the last commit and keep changes unstaged')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Undo result')]
function commit_undo(
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'Non-interactive: use defaults, no prompts')]
    bool $quiet = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    if ($agent) {
        $quiet = true;
    }
    $handler = new \App\Handler\CommitUndoHandler(_get_git_repository(), _get_logger(), _get_translation_service());
    $exitCode = $handler->handle(io(), $quiet);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Commit undone', 'Undo failed'));

        return;
    }
    exit($exitCode);
}

#[AsTask(name: 'flatten', aliases: ['ft'], description: 'Automatically squash all fixup! commits into their target commits')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Flatten result')]
function flatten(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $handler = new FlattenHandler(_get_git_repository(), _get_base_branch(), _get_translation_service(), _get_logger());
    $exitCode = $handler->handle(io());
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Flatten completed', 'Flatten failed'));

        return;
    }
    exit($exitCode);
}

#[AsTask(name: 'sync', aliases: ['sy'], description: 'Fetch the latest base branch and rebase the current feature branch onto it')]
#[AgentOutput(properties: ['message' => 'string', 'rebased' => 'bool'], description: 'Sync result')]
function sync(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $handler = new SyncHandler(_get_git_repository(), _get_git_branch_service(), _get_base_branch(), _get_translation_service(), _get_logger());
    $exitCode = $handler->handle(io());
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Sync completed', 'Sync failed'));

        return;
    }
    exit($exitCode);
}

#[AsTask(name: 'cache:clear', aliases: ['cc'], description: 'Clear the update check cache to force a version check on next command')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Cache clear result')]
function cache_clear(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $handler = new CacheClearHandler(_get_translation_service(), _get_logger(), _get_file_system());
    $exitCode = $handler->handle(io());
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Cache cleared', 'Cache clear failed'));

        return;
    }
    exit($exitCode);
}

#[AsTask(name: 'submit', aliases: ['su'], description: 'Pushes the current branch and creates a Pull Request')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Submit result')]
function submit(
    #[AsOption(name: 'draft', shortcut: 'd', description: 'Create a Draft Pull Request')]
    bool $draft = false,
    #[AsOption(name: 'labels', description: 'Comma-separated list of labels to apply to the Pull Request')]
    ?string $labels = null,
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'Non-interactive: use defaults, no prompts')]
    bool $quiet = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    /** @var array<string, mixed>|null $agentSubmitInput when set, stageAll was true and push phase must run first */
    $agentSubmitInput = null;
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $draft = (bool) ($input['draft'] ?? false);
        $labels = $input['labels'] ?? null;
        $quiet = true;
        if (($input['stageAll'] ?? false) === true) {
            $agentSubmitInput = $input;
        }
    }
    $gitRepository = _get_git_repository();
    $gitProvider = _get_git_provider($quiet);

    if ($gitProvider === null) {
        if ($agent) {
            $cmdResponder = new AgentCommandResponder();
            _agent_respond(new AgentJsonResponse(false, error: 'Git provider not configured'));

            return;
        }
        $gitConfig = _get_git_config();
        $repoOwner = $gitRepository->getRepositoryOwner();
        $repoName = $gitRepository->getRepositoryName();

        if (! $repoOwner || ! $repoName) {
            _get_logger()->error(Logger::VERBOSITY_NORMAL, [
                'Could not determine repository owner or name from git remote.',
                'Please ensure your repository has a remote named "origin" configured.',
                'You can check with: git remote -v',
            ]);
            exit(1);
        }

        $providerType = $gitConfig['GIT_PROVIDER'] ?? 'unknown';
        _get_logger()->error(Logger::VERBOSITY_NORMAL, [
            "Git provider '{$providerType}' is not supported or not properly configured.",
            'Please check your configuration file and ensure GIT_PROVIDER is set to "github" or "gitlab".',
        ]);
        exit(1);
    }

    if ($agent && $agentSubmitInput !== null) {
        $pushExit = _agent_submit_run_push_phase(io(), $agentSubmitInput);
        if ($pushExit !== 0) {
            $cmdResponder = new AgentCommandResponder();
            _agent_respond($cmdResponder->respondFromExitCode($pushExit, 'Pull request created', 'Commit or push before submit failed'));

            return;
        }
    }

    $handler = new SubmitHandler($gitRepository, _get_jira_service(), $gitProvider, _get_jira_config(), _get_base_branch($quiet), _get_translation_service(), _get_logger(), _get_html_converter());
    $exitCode = $handler->handle(io(), $draft, $labels, $quiet);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Pull request created', 'Submit failed'));

        return;
    }
}

#[AsTask(name: 'pr:comment', aliases: ['pc'], description: 'Posts a comment to the active Pull Request')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Comment post result')]
function pr_comment(
    #[AsArgument(name: 'message', description: 'The comment message (or inputFile when --agent; optional if piping from STDIN)')]
    ?string $message = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    if ($agent) {
        $input = _read_agent_input($message);
        if ($input === null) {
            return;
        }
        $message = $input['message'] ?? null;
    }
    $gitRepository = _get_git_repository();
    $gitProvider = _get_git_provider();
    $handler = new PrCommentHandler($gitRepository, $gitProvider, _get_translation_service(), _get_logger());
    $exitCode = $handler->handle(io(), $message);
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Comment posted', 'Comment failed'));

        return;
    }
    exit($exitCode);
}

#[AsTask(name: 'pr:comments', aliases: ['pcs'], description: 'Fetches and displays issue and review comments for the active Pull Request')]
#[AgentOutput(responseClass: \App\Response\PrCommentsResponse::class, description: 'Pull request comments and reviews')]
function pr_comments(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;

    $gitRepository = _get_git_repository();
    $gitProvider = _get_git_provider();
    $handler = new PrCommentsHandler($gitRepository, $gitProvider, _get_translation_service());
    $response = $handler->handle();
    $responder = new PrCommentsResponder(_get_responder_helper(), _get_comment_body_parser(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'confluence:push', aliases: ['cpu'], description: 'Create or update a Confluence page from markdown content')]
#[AgentOutput(properties: ['pageId' => 'string', 'title' => 'string', 'url' => 'string', 'action' => 'string'], description: 'Created or updated page ID, title, URL, and action (created|updated)')]
function confluence_push(
    #[AsOption(name: 'space', shortcut: 's', description: 'Confluence space key (e.g. DEV)')]
    ?string $space = null,
    #[AsOption(name: 'title', shortcut: 't', description: 'Page title')]
    ?string $title = null,
    #[AsOption(name: 'file', shortcut: 'f', description: 'Path to markdown file (create or update); if omitted, read from STDIN')]
    ?string $file = null,
    #[AsOption(name: 'page', shortcut: 'p', description: 'Existing page ID to update (omit to create new page)')]
    ?string $page = null,
    #[AsOption(name: 'parent', description: 'Parent page ID for nesting (create only)')]
    ?string $parent = null,
    #[AsOption(name: 'url', description: 'Override Confluence base URL')]
    ?string $url = null,
    #[AsOption(name: 'status', description: 'Page status: current (default) or draft')]
    ?string $status = null,
    #[AsOption(name: 'contact-email', description: 'Append "Contact: @User" at bottom (resolve user by email via Jira)')]
    ?string $contactEmail = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    $confluenceUrl = $url;
    $content = '';
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $filePath = isset($input['file']) && is_string($input['file']) ? trim($input['file']) : '';
        if ($filePath !== '') {
            if (is_readable($filePath)) {
                $content = (string) file_get_contents($filePath);
            } else {
                $translator = _get_translation_service();
                $message = $translator->trans('confluence.push.error_file_not_readable', ['%path%' => $filePath]);
                _agent_output_and_exit(_get_agent_mode_helper(), _get_agent_mode_helper()->buildErrorPayload($message));
            }
        } elseif (isset($input['content'])) {
            $content = (string) $input['content'];
        } else {
            $content = '';
        }
        $space = isset($input['space']) ? (string) $input['space'] : $space;
        $title = isset($input['title']) ? (string) $input['title'] : $title;
        $page = isset($input['page']) ? (string) $input['page'] : (isset($input['pageId']) ? (string) $input['pageId'] : $page);
        $parent = isset($input['parent']) ? (string) $input['parent'] : (isset($input['parentId']) ? (string) $input['parentId'] : $parent);
        $status = isset($input['status']) ? (string) $input['status'] : ($status ?? 'current');
        $contactEmail = isset($input['contactEmail']) && (string) $input['contactEmail'] !== '' ? (string) $input['contactEmail'] : $contactEmail;
        if (isset($input['url']) && $input['url'] !== '') {
            $confluenceUrl = (string) $input['url'];
        }
    } else {
        if ($file !== null && $file !== '') {
            if (! is_readable($file)) {
                $translator = _get_translation_service();
                _get_logger()->error(Logger::VERBOSITY_NORMAL, $translator->trans('confluence.push.error_file_not_readable', ['%path%' => $file]));
                exit(1);
            }
            $content = (string) file_get_contents($file);
        } else {
            $content = (string) file_get_contents('php://stdin');
        }
        $status = $status ?? 'current';
        if ($space === null || $space === '') {
            $gitRepo = _get_git_repository();
            $projectConfig = $gitRepo->readProjectConfig();
            /** @var array<string, mixed> $projectConfig */
            $space = isset($projectConfig['CONFLUENCE_DEFAULT_SPACE']) && is_string($projectConfig['CONFLUENCE_DEFAULT_SPACE'])
                ? $projectConfig['CONFLUENCE_DEFAULT_SPACE']
                : null;
        }
    }
    $contactAccountId = null;
    $contactDisplayName = null;
    if ($contactEmail !== null && trim($contactEmail) !== '') {
        try {
            $jiraService = _get_jira_service();
            $user = $jiraService->findUserByEmail(trim($contactEmail));
            if ($user !== null) {
                $contactAccountId = $user['accountId'];
                $contactDisplayName = $user['displayName'];
                _get_logger()->writeln(Logger::VERBOSITY_VERBOSE, '<info>Contact resolved: @' . $contactDisplayName . ' (accountId: ' . $contactAccountId . ')</info>');
            } else {
                _get_logger()->warning(Logger::VERBOSITY_NORMAL, "Contact email \"{$contactEmail}\" could not be resolved to a user (Jira user search returned no match). Contact block was not added.");
            }
        } catch (\Throwable $e) {
            _get_logger()->warning(Logger::VERBOSITY_NORMAL, "Could not resolve contact email \"{$contactEmail}\": " . $e->getMessage() . '. Contact block was not added.');
        }
    }
    $baseUrl = _get_confluence_base_url($confluenceUrl);
    $confluenceService = _get_confluence_service($confluenceUrl);
    $converter = new \App\Service\MarkdownToAdfConverter();
    $handler = new ConfluencePushHandler($confluenceService, $converter, _get_translation_service());
    $inputDto = new ConfluencePushInput($content, $space, $title, $page !== null && $page !== '' ? $page : null, $parent !== null && $parent !== '' ? $parent : null, $status, $contactAccountId, $contactDisplayName);
    $response = $handler->handle($inputDto, $baseUrl);
    $responder = new ConfluencePushResponder(_get_translation_service(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'confluence:page-labels', description: 'Add labels to a Confluence page')]
#[AgentOutput(properties: ['labels' => 'array'], description: 'Labels added to the page')]
function confluence_page_labels(
    #[AsOption(name: 'page', shortcut: 'p', description: 'Page ID')]
    string $page = '',
    #[AsOption(name: 'labels', shortcut: 'l', description: 'Comma-separated label names (e.g. R&D,DX)')]
    string $labels = '',
    #[AsOption(name: 'url', description: 'Override Confluence base URL')]
    ?string $url = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    $pageId = trim($page);
    if ($pageId === '') {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, 'Page ID is required. Use --page/-p.');
        exit(1);
    }
    $labelList = array_values(array_filter(array_map('trim', explode(',', $labels))));
    if ($labelList === []) {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, 'At least one label is required. Use --labels/-l (e.g. --labels "R&D,DX").');
        exit(1);
    }

    try {
        $confluenceService = _get_confluence_service($url);
        _get_logger()->writeln(Logger::VERBOSITY_VERBOSE, '<info>POST rest/api/content/' . $pageId . '/label with: ' . implode(', ', $labelList) . '</info>');
        $confluenceService->addPageLabels($pageId, $labelList);
        _get_logger()->success(Logger::VERBOSITY_NORMAL, 'Labels added: ' . implode(', ', $labelList));
    } catch (\App\Exception\ApiException $e) {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, $e->getMessage() . ($e->getTechnicalDetails() !== '' ? ' ' . $e->getTechnicalDetails() : ''));
        exit(1);
    }
}

#[AsTask(name: 'confluence:show', aliases: ['csh'], description: 'Fetch and display a Confluence page by ID or URL')]
#[AgentOutput(properties: ['id' => 'string', 'title' => 'string', 'url' => 'string', 'body' => 'string'], description: 'Page id, title, url, and body content (markdown)')]
function confluence_show(
    #[AsOption(name: 'page', shortcut: 'p', description: 'Confluence page ID')]
    ?string $page = null,
    #[AsOption(name: 'url', description: 'Confluence page URL (e.g. .../wiki/spaces/SPACE/pages/123456)')]
    ?string $url = null,
    #[AsOption(name: 'confluence-url', description: 'Override Confluence base URL')]
    ?string $confluenceUrl = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $format = $agent ? OutputFormat::Json : OutputFormat::Cli;
    $pageId = $page !== null && trim($page) !== '' ? trim($page) : null;
    $pageUrl = $url !== null && trim($url) !== '' ? trim($url) : null;
    $baseUrlOverride = $confluenceUrl !== null && trim($confluenceUrl) !== '' ? trim($confluenceUrl) : null;

    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $fromInput = isset($input['pageId']) && is_string($input['pageId']) && trim($input['pageId']) !== ''
            ? trim($input['pageId'])
            : (isset($input['page']) && is_string($input['page']) && trim($input['page']) !== '' ? trim($input['page']) : null);
        $pageId = $fromInput ?? $pageId;
        $pageUrl = isset($input['url']) && is_string($input['url']) && trim($input['url']) !== ''
            ? trim($input['url'])
            : $pageUrl;
        if (isset($input['confluenceUrl']) && is_string($input['confluenceUrl']) && trim($input['confluenceUrl']) !== '') {
            $baseUrlOverride = trim($input['confluenceUrl']);
        }
    }

    if ($pageId === null && $pageUrl === null) {
        $translator = _get_translation_service();
        if ($format === OutputFormat::Json) {
            _agent_output_and_exit(_get_agent_mode_helper(), _get_agent_mode_helper()->buildErrorPayload($translator->trans('confluence.show.error_page_or_url_required')));
        }
        _get_logger()->error(Logger::VERBOSITY_NORMAL, $translator->trans('confluence.show.error_page_or_url_required'));
        exit(1);
    }

    $baseUrl = _get_confluence_base_url($baseUrlOverride);
    $confluenceService = _get_confluence_service($baseUrlOverride);
    $adfConverter = new \App\Service\AdfToMarkdownConverter();
    $handler = new ConfluenceShowHandler($confluenceService, $adfConverter, _get_translation_service());
    $inputDto = new ConfluenceShowInput($pageId, $pageUrl);
    $response = $handler->handle($inputDto, $baseUrl);
    $responder = new ConfluenceShowResponder(_get_responder_helper(), _get_logger());
    $agentResponse = $responder->respond(io(), $response, $format);
    if ($agentResponse !== null) {
        _agent_respond($agentResponse);

        return;
    }
    if (! $response->isSuccess()) {
        _get_error_responder()->respond(io(), $response);
        exit(1);
    }
}

#[AsTask(name: 'help', description: 'Displays a list of available commands')]
#[AgentOutput(properties: ['commands' => 'array'], description: 'Agent mode schema with all commands')]
function help(
    #[AsArgument(name: 'command_name', description: 'The command name to get help for')]
    ?string $commandName = null,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    if ($agent) {
        $input = _read_agent_input($commandName);
        if ($input === null) {
            return;
        }
        $generator = new \App\Service\AgentModeSchemaGenerator();
        $schema = $generator->generate();
        $filterCommand = $input['commandName'] ?? $input['command'] ?? null;
        if ($filterCommand !== null) {
            foreach ($schema['commands'] as $cmd) {
                if ($cmd['name'] === $filterCommand || in_array($filterCommand, $cmd['aliases'] ?? [], true)) {
                    _agent_respond(new AgentJsonResponse(true, data: $cmd));

                    return;
                }
            }
            _agent_respond(new AgentJsonResponse(false, error: "Unknown command: {$filterCommand}"));

            return;
        }
        _agent_respond(new AgentJsonResponse(true, data: $schema));

        return;
    }
    $translator = _get_translation_service();
    $colorHelper = _get_color_helper();

    // Register color styles for help output
    _get_logger()->registerStyles($colorHelper);

    // If a command is provided, show help for that specific command
    if ($commandName !== null) {
        $helpService = new \App\Service\HelpService($translator, _get_file_system());

        $aliasMap = CommandMap::aliasLookupMap();
        $mappedCommandName = $aliasMap[$commandName] ?? $commandName;
        $helpService->displayCommandHelp(_get_logger(), $mappedCommandName);

        return;
    }

    // Otherwise, show general help
    $logger = _get_logger();
    $logo = require APP_LOGO_PATH;
    $logger->writeln(Logger::VERBOSITY_NORMAL, $logo(APP_NAME, APP_VERSION));
    $logger->title(Logger::VERBOSITY_NORMAL, $translator->trans('help.title'));

    $sectionTitle = $translator->trans('help.description_section');
    if ($colorHelper !== null) {
        $sectionTitle = $colorHelper->format('section_title', $sectionTitle);
    }
    $logger->section(Logger::VERBOSITY_NORMAL, $sectionTitle);
    $logger->writeln(Logger::VERBOSITY_NORMAL, '    ' . $translator->trans('help.description_text'));
    // $logger->newLine(Logger::VERBOSITY_NORMAL);
    // $logger->note(Logger::VERBOSITY_NORMAL, $translator->trans('help.universal_help_note'));
    $logger->newLine(Logger::VERBOSITY_NORMAL);
    $logger->note(Logger::VERBOSITY_NORMAL, $translator->trans('help.command_specific_help_note'));

    $sectionTitle = $translator->trans('help.global_options_section');
    if ($colorHelper !== null) {
        $sectionTitle = $colorHelper->format('section_title', $sectionTitle);
    }
    $logger->section(Logger::VERBOSITY_NORMAL, $sectionTitle);
    $verboseOption = '-v|vv|vvv, --verbose';
    $verboseDesc = $translator->trans('help.global_option_verbose');
    if ($colorHelper !== null) {
        $verboseOption = $colorHelper->format('definition_key', $verboseOption);
        $verboseDesc = $colorHelper->format('definition_value', $verboseDesc);
    }
    $logger->definitionList(
        Logger::VERBOSITY_NORMAL,
        // ['-h, --help' => $translator->trans('help.global_option_help')],
        // ['-s, --silent' => $translator->trans('help.global_option_silent')],
        // ['-q, --quiet' => $translator->trans('help.global_option_quiet')],
        [$verboseOption => $verboseDesc],
    );

    $sectionTitle = $translator->trans('help.commands_section');
    if ($colorHelper !== null) {
        $sectionTitle = $colorHelper->format('section_title', $sectionTitle);
    }
    $logger->section(Logger::VERBOSITY_NORMAL, $sectionTitle);
    $logger->writeln(Logger::VERBOSITY_NORMAL, '  <info>stud</info> [command|alias] [-options] [arguments]');
    $commands = [
        $translator->trans('help.category_configuration') => [
            [
                'name' => 'config:init',
                'alias' => 'init',
                'description' => $translator->trans('help.command_config_init'),
            ],
            [
                'name' => 'config:show',
                'description' => $translator->trans('help.command_config_show'),
            ],
            [
                'name' => 'config:validate',
                'description' => $translator->trans('help.command_config_validate'),
            ],
            [
                'name' => 'config:project-init',
                'alias' => 'cpi',
                'description' => $translator->trans('help.command_config_project_init'),
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
                'example' => 'stud ls -p PROJ -s Key',
            ],
            [
                'name' => 'items:show',
                'alias' => 'sh',
                'args' => '<key>',
                'description' => $translator->trans('help.command_items_show'),
                'example' => 'stud sh PROJ-123',
            ],
            [
                'name' => 'items:create',
                'alias' => 'ic',
                'description' => $translator->trans('help.command_items_create'),
                'example' => 'stud ic -p PROJ -m "Summary"',
            ],
            [
                'name' => 'items:search',
                'alias' => 'search',
                'args' => '<jql>',
                'description' => $translator->trans('help.command_items_search'),
                'example' => 'stud search "project = PROJ and status = Done"',
            ],
            [
                'name' => 'items:transition',
                'alias' => 'tx',
                'args' => '[<key>]',
                'description' => $translator->trans('help.command_items_transition'),
                'example' => 'stud tx PROJ-123',
            ],
            [
                'name' => 'filters:list',
                'alias' => 'fl',
                'description' => $translator->trans('help.command_filters_list'),
            ],
            [
                'name' => 'filters:show',
                'alias' => 'fs',
                'args' => '<filterName>',
                'description' => $translator->trans('help.command_filters_show'),
                'example' => 'stud fs "My Filter"',
            ],
        ],
        $translator->trans('help.category_confluence') => [
            [
                'name' => 'confluence:push',
                'alias' => 'cpu',
                'args' => '[inputFile]',
                'description' => $translator->trans('help.command_confluence_push'),
            ],
            [
                'name' => 'confluence:show',
                'alias' => 'csh',
                'args' => '[inputFile]',
                'description' => $translator->trans('help.command_confluence_show'),
            ],
            [
                'name' => 'confluence:page-labels',
                'alias' => null,
                'args' => '',
                'description' => $translator->trans('help.command_confluence_page_labels'),
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
                'name' => 'items:takeover',
                'alias' => 'to',
                'args' => '<key>',
                'description' => $translator->trans('help.command_items_takeover'),
                'example' => 'stud to PROJ-123',
            ],
            [
                'name' => 'branch:rename',
                'alias' => 'rn',
                'args' => '[<branch>] [<key>]',
                'description' => $translator->trans('help.command_branch_rename'),
                'example' => 'stud rn feat/OLD-123-old ACME-4067',
            ],
            [
                'name' => 'commit',
                'alias' => 'co',
                'description' => $translator->trans('help.command_commit'),
            ],
            [
                'name' => 'commit:undo',
                'alias' => 'undo',
                'description' => $translator->trans('help.command_commit_undo'),
            ],
            [
                'name' => 'push',
                'alias' => 'ps',
                'description' => $translator->trans('help.command_push'),
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
                'name' => 'pr:comment',
                'alias' => 'pc',
                'args' => '[<message>]',
                'description' => $translator->trans('help.command_pr_comment'),
            ],
            [
                'name' => 'pr:comments',
                'alias' => 'pcs',
                'description' => $translator->trans('help.command_pr_comments'),
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
        $logger->writeln(Logger::VERBOSITY_NORMAL, "\n  <fg=yellow>{$category}</>");
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
        $logger->table(
            Logger::VERBOSITY_NORMAL,
            [
                $translator->trans('table.command'),
                $translator->trans('table.alias'),
                $translator->trans('table.description'),
            ],
            $tableRows,
        );
    }
}


#[AsTask(name: 'status', aliases: ['ss'], description: 'A quick "where am I?" dashboard')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Status result')]
function status(
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    $handler = new StatusHandler(_get_git_repository(), _get_jira_service(), _get_translation_service(), _get_logger());
    $exitCode = $handler->handle(io());
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondFromExitCode($exitCode, 'Status displayed', 'Status failed'));

        return;
    }
}

// =================================================================================
// Release Commands
// =================================================================================

#[AsTask(name: 'release', aliases: ['rl'], description: 'Creates a new release branch and bumps the version')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Release creation result')]
function release(
    #[AsArgument(name: 'version', description: 'The new version (or inputFile when --agent). Optional if using --major, --minor, or --patch flags')]
    ?string $version = null,
    #[AsOption(name: 'major', shortcut: 'M', description: 'Increment major version (X.0.0)')]
    bool $major = false,
    #[AsOption(name: 'minor', shortcut: 'm', description: 'Increment minor version (X.Y.0)')]
    bool $minor = false,
    #[AsOption(name: 'patch', shortcut: 'b', description: 'Increment patch version (X.Y.Z). This is the default if no flags are provided')]
    bool $patch = false,
    #[AsOption(name: 'publish', shortcut: 'p', description: 'Publish the release branch to the remote')]
    bool $publish = false,
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'Non-interactive: use defaults, no prompts')]
    bool $quiet = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
): void {
    _load_constants();
    if ($agent) {
        $input = _read_agent_input($version);
        if ($input === null) {
            return;
        }
        $version = $input['version'] ?? null;
        $bumpType = $input['bumpType'] ?? ($version === null ? 'patch' : null);
        $publish = (bool) ($input['publish'] ?? false);
        $quiet = true;
        $handler = new ReleaseHandler(_get_git_repository(), _get_translation_service(), _get_logger(), _get_file_system());
        $handler->handle(io(), $version, $publish, $bumpType, $quiet);
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondSuccess('Release created'));

        return;
    }

    $flagCount = ($major ? 1 : 0) + ($minor ? 1 : 0) + ($patch ? 1 : 0);
    if ($flagCount > 1) {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, 'Only one of --major, --minor, or --patch can be specified at a time.');
        exit(1);
    }

    $bumpType = null;
    if ($major) {
        $bumpType = 'major';
    } elseif ($minor) {
        $bumpType = 'minor';
    } elseif ($patch) {
        $bumpType = 'patch';
    } elseif ($version === null) {
        $bumpType = 'patch';
    }

    if ($version !== null && $bumpType !== null) {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, 'Cannot specify both a version and a bump flag (--major, --minor, --patch).');
        exit(1);
    }

    $handler = new ReleaseHandler(_get_git_repository(), _get_translation_service(), _get_logger(), _get_file_system());
    $handler->handle(io(), $version, $publish, $bumpType, $quiet);
}

#[AsTask(name: 'deploy', aliases: ['mep'], description: 'Deploys the current release branch')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Deployment result')]
function deploy(
    #[AsOption(name: 'clean', description: 'Clean up merged branches after deployment')]
    bool $clean = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    if ($agent) {
        $input = _read_agent_input($inputFile);
        if ($input === null) {
            return;
        }
        $clean = (bool) ($input['clean'] ?? false);
    }
    $handler = new DeployHandler(_get_git_repository(), _get_base_branch(), _get_translation_service(), _get_logger());
    $handler->handle(io());

    if ($clean) {
        $gitRepository = _get_git_repository();
        $gitBranchService = _get_git_branch_service();
        $githubProvider = _get_github_provider();
        $resolver = new \App\Service\BranchDeletionEligibilityResolver($gitRepository, $gitBranchService, $githubProvider);
        $cleanHandler = new BranchCleanHandler($gitRepository, $gitBranchService, $resolver, _get_configured_base_branch_or_null(), _get_translation_service(), _get_logger());
        $cleanHandler->handle(io(), true);
    }
    if ($agent) {
        $cmdResponder = new AgentCommandResponder();
        _agent_respond($cmdResponder->respondSuccess('Deployment completed'));

        return;
    }
}

#[AsTask(name: 'update', aliases: ['up'], description: 'Checks for and installs new versions of the tool')]
#[AgentOutput(properties: ['message' => 'string'], description: 'Update is not supported in agent mode')]
function update(
    #[AsOption(name: 'info', shortcut: 'i', description: 'Preview the changelog of the latest available version without downloading')]
    bool $info = false,
    #[AsOption(name: 'quiet', shortcut: 'q', description: 'Non-interactive: use defaults, no prompts')]
    bool $quiet = false,
    #[AsOption(name: 'agent', description: 'JSON input/output mode')]
    bool $agent = false,
    #[AsArgument(name: 'inputFile', description: 'Path to JSON input file (--agent mode)')]
    ?string $inputFile = null,
): void {
    _load_constants();
    if ($agent) {
        _agent_respond(new AgentJsonResponse(false, error: 'Update is not supported in agent mode'));

        return;
    }

    $binaryPath = '';
    if (class_exists('Phar') && \Phar::running(false)) {
        $binaryPath = \Phar::running(false);
    } else {
        $binaryPath = __FILE__;
    }

    $gitToken = null;

    try {
        $gitConfig = _get_git_config();
        $gitToken = $gitConfig['GITHUB_TOKEN'] ?? null;
    } catch (\Exception) {
    }

    if (! defined('APP_REPO_SLUG')) {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, [
            'APP_REPO_SLUG constant is not defined.',
            'Please ensure the application was built correctly.',
        ]);
        exit(1);
    }

    $nameParts = explode('/', APP_REPO_SLUG, 2);
    if (count($nameParts) !== 2) {
        _get_logger()->error(Logger::VERBOSITY_NORMAL, [
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
        _get_logger(),
        _get_file_system(),
        $gitToken
    );
    $result = $handler->handle(io(), $info, $quiet);
    exit($result);
}
