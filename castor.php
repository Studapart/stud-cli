<?php

declare(strict_types=1);

use App\DTO\Project;
use App\DTO\WorkItem;
use App\Jira\JiraService;
use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use function Castor\io;
use function Castor\run;

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
 * Reads Jira configuration from the YAML file.
 * Throws an exception if the config is not found or incomplete.
 */
function _get_jira_config(): array
{
    $configPath = _get_config_path();
    if (!file_exists($configPath)) {
        io()->error([
            'Configuration file not found at: ' . $configPath,
            'Please run "stud config:init" to create one.',
        ]);
        exit(1);
    }

    $config = Yaml::parseFile($configPath);
    $missingKeys = array_diff(['JIRA_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN'], array_keys($config));

    if (!empty($missingKeys)) {
        io()->error([
            'Your configuration file is missing required keys: ' . implode(', ', $missingKeys),
            'Please run "stud config:init" again.',
        ]);
        exit(1);
    }

    return $config;
}

function _get_jira_service(): JiraService
{
    $config = _get_jira_config();
    $auth = base64_encode($config['JIRA_EMAIL'] . ':' . $config['JIRA_API_TOKEN']);

    $client = HttpClient::createForBaseUri($config['JIRA_URL'], [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
    ]);

    return new JiraService($client);
}

/**
 * Executes a shell command using Symfony Process and returns the Process object.
 */
function _run_process(string $command, bool $mustRun = true): Process
{
    $process = Process::fromShellCommandline($command);
    if ($mustRun) {
        $process->mustRun();
    } else {
        $process->run();
    }
    return $process;
}

/**
 * Parses the Jira issue key from the current Git branch name.
 * Returns the key or null if not found.
 */
function _get_key_from_branch(): ?string
{
    $branch = _run_process('git rev-parse --abbrev-ref HEAD')->getOutput();
    preg_match('/(?i)([a-z]+-\d+)/', $branch, $matches);

    return isset($matches[1]) ? strtoupper($matches[1]) : null;
}

/**
 * Converts a string into a URL-friendly slug.
 */
function _slugify(string $string): string
{
    // Lowercase, remove accents, remove non-word chars, and replace spaces with hyphens.
    $string = strtolower(trim($string));
    $string = preg_replace('/[^\w\s-]/', '', $string);
    $string = preg_replace('/[\s_-]+/', '-', $string);
    return trim($string, '-');
}

/**
 * Maps a Jira issue type to a conventional Git branch prefix.
 */
function _get_branch_prefix_from_issue_type(string $issueType): string
{
    return match (strtolower($issueType)) {
        'bug' => 'fix',
        'story', 'epic' => 'feat',
        'task', 'sub-task' => 'chore',
        default => 'feat',
    };
}

/**
 * Maps a Jira issue type to a conventional commit type.
 */
function _get_commit_type_from_issue_type(string $issueType): string
{
    return match (strtolower($issueType)) {
        'bug' => 'fix',
        'story', 'epic' => 'feat',
        'task', 'sub-task' => 'chore',
        default => 'feat',
    };
}

// =================================================================================
// Configuration Command
// =================================================================================

#[AsTask(name: 'config:init', description: 'Interactive wizard to set up Jira connection details')]
function config_init(): void
{
    $configPath = _get_config_path();
    io()->title('Jira Configuration Wizard');
    io()->text([
        'This will create a configuration file at: ' . $configPath,
        'You can generate an API token here: https://id.atlassian.com/manage-profile/security/api-tokens',
    ]);

    $jiraUrl = io()->ask('Enter your Jira URL (e.g., https://your-company.atlassian.net)');
    $jiraEmail = io()->ask('Enter your Jira email address');
    $jiraToken = null;
    while (!$jiraToken) {
        $jiraToken = io()->askHidden('Enter your Jira API token (cannot be empty)');
    }

    $config = [
        'JIRA_URL' => rtrim($jiraUrl, '/'),
        'JIRA_EMAIL' => $jiraEmail,
        'JIRA_API_TOKEN' => $jiraToken,
    ];

    $configDir = dirname($configPath);
    if (!is_dir($configDir)) {
        mkdir($configDir, 0700, true);
    }

    file_put_contents($configPath, Yaml::dump($config));
    io()->success('Configuration saved successfully!');
}

// =================================================================================
// "Noun" Commands (Jira Info)
// =================================================================================

#[AsTask(name: 'projects:list', aliases: ['pj'], description: 'Lists all visible Jira projects')]
function projects_list(): void
{
    io()->section('Fetching Jira Projects');
    $jira = _get_jira_service();
    try {
        $projects = $jira->getProjects();
    } catch (\Exception $e) {
        io()->error('Failed to fetch projects: ' . $e->getMessage());
        return;
    }

    if (empty($projects)) {
        io()->note('No projects found.');
        return;
    }

    $table = array_map(fn (Project $project) => [$project->key, $project->name], $projects);
    io()->table(['Key', 'Name'], $table);
}

#[AsTask(name: 'items:list', aliases: ['ls'], description: 'Lists active work items (your dashboard)')]
function items_list(
    #[AsOption(name: 'all', shortcut: 'a', description: 'List items for all users')] bool $all = false,
    #[AsOption(name: 'project', shortcut: 'p', description: 'Filter by project key')] ?string $project = null
): void {
    io()->section('Fetching Jira Items');

    $jqlParts = [];
    if (!$all) {
        $jqlParts[] = 'assignee = currentUser()';
    }
    $jqlParts[] = "status in ('To Do', 'In Progress')";
    if ($project) {
        $jqlParts[] = 'project = ' . strtoupper($project);
    }

    $jql = implode(' AND ', $jqlParts) . ' ORDER BY updated DESC';

    $jira = _get_jira_service();
    try {
        $issues = $jira->searchIssues($jql);
    } catch (\Exception $e) {
        io()->error('Failed to fetch items: ' . $e->getMessage());
        return;
    }

    if (empty($issues)) {
        io()->note('No items found matching your criteria.');
        return;
    }

    $table = array_map(fn (WorkItem $issue) => [$issue->key, $issue->status, $issue->title], $issues);
    io()->table(['Key', 'Status', 'Summary'], $table);
}

#[AsTask(name: 'issues:search', aliases: ['search'], description: 'Search for issues using JQL')]
function issues_search(
    #[AsArgument(name: 'jql', description: 'The JQL query string')] string $jql
): void {
    io()->section('Searching Jira issues with JQL');
    $jira = _get_jira_service();
    try {
        $issues = $jira->searchIssues($jql);
    } catch (\Exception $e) {
        io()->error('Failed to search for issues: ' . $e->getMessage());
        return;
    }

    if (empty($issues)) {
        io()->note('No issues found matching your JQL query.');
        return;
    }

    $table = array_map(fn (WorkItem $issue) => [$issue->key, $issue->status, $issue->title], $issues);
    io()->table(['Key', 'Status', 'Summary'], $table);
}


#[AsTask(name: 'items:show', aliases: ['sh'], description: 'Shows detailed info for one work item')]
function items_show(
    #[AsArgument(name: 'key', description: 'The Jira issue key (e.g., PROJ-123)')] string $key
): void {
    $key = strtoupper($key);
    io()->section("Details for issue {$key}");
    $jira = _get_jira_service();
    try {
        $issue = $jira->getIssue($key);
    } catch (\Exception $e) {
        io()->error("Could not find Jira issue with key \"{$key}\".");
        return;
    }
    $config = _get_jira_config();

    io()->definitionList(
        ['Key', $issue->key],
        ['Title', $issue->title],
        ['Status', $issue->status],
        ['Assignee', $issue->assignee],
        ['Type', $issue->issueType],
        ['Labels', !empty($issue->labels) ? implode(', ', $issue->labels) : 'None'],
        new TableSeparator(), // separator
        ['Description', $issue->description],
        new TableSeparator(), // separator
        ['Link', $config['JIRA_URL'] . '/browse/' . $issue->key]
    );
}

// =================================================================================
// "Verb" Commands (Git Workflow)
// =================================================================================

#[AsTask(name: 'items:start', aliases: ['start'], description: 'Creates a new git branch from a Jira item')]
function items_start(
    #[AsArgument(name: 'key', description: 'The Jira issue key (e.g., PROJ-123)')] string $key
): void {
    $key = strtoupper($key);
    io()->section("Starting work on {$key}");

    $jira = _get_jira_service();
    try {
        $issue = $jira->getIssue($key);
    } catch (\Exception $e) {
        io()->error("Could not find Jira issue with key \"{$key}\".");
        return;
    }

    $prefix = _get_branch_prefix_from_issue_type($issue->issueType);
    $slug = _slugify($issue->title);
    $branchName = "{$prefix}/{$key}-{$slug}";

    io()->text("Fetching latest changes from origin...");
    _run_process('git fetch origin');

    io()->text("Creating new branch: <info>{$branchName}</info>");
    _run_process("git switch -c {$branchName} " . DEFAULT_BASE_BRANCH);

    io()->success("Branch '{$branchName}' created from '" . DEFAULT_BASE_BRANCH . "'.");
}

#[AsTask(name: 'commit', aliases: ['co'], description: 'Guides you through making a conventional commit')]
function commit(
    #[AsOption(name: 'new', description: 'Create a new logical commit instead of a fixup')] bool $isNew = false
): void
{
    io()->section('Conventional Commit Helper');

    // 1. Auto-Fixup Strategy: Find the latest logical commit
    $latestLogicalSha = null;
    if (!$isNew) {
        $process = _run_process(
            'git log ' . DEFAULT_BASE_BRANCH . '..HEAD --format=%H --grep="^fixup!" --grep="^squash!" --invert-grep --max-count=1',
            mustRun: false // Don't fail if no commits are found
        );
        if ($process->isSuccessful()) {
            $latestLogicalSha = trim($process->getOutput());
        }
    }

    // 2. If a logical commit is found and --new is not used, create a fixup commit
    if ($latestLogicalSha) {
        io()->text('Staging all changes...');
        _run_process('git add -A');

        io()->text("Creating fixup commit for <info>{$latestLogicalSha}</info>...");
        _run_process("git commit --fixup {$latestLogicalSha}");

        io()->success("✅ Changes saved as a fixup for commit {$latestLogicalSha}.");
        return;
    }

    // 3. If no logical commit is found OR --new is used, run the interactive prompter
    io()->note('No previous logical commit found or --new flag used. Starting interactive prompter...');

    $key = _get_key_from_branch();
    if (!$key) {
        io()->error([
            'Could not find a Jira key in your current branch name.',
            'Please use "stud start <key>" to create a branch.',
        ]);
        exit(1);
    }

    $jira = _get_jira_service();
    try {
        // The getIssue method now fetches components as well
        $issue = $jira->getIssue($key);
    } catch (\Exception $e) {
        io()->error("Could not find Jira issue with key \"{$key}\".");
        return;
    }

    $detectedType = _get_commit_type_from_issue_type($issue->issueType);
    $detectedSummary = $issue->title;

    // 4. Upgraded Interactive Prompter with Scope Inference
    $scopePrompt = 'Scope (optional)';
    $defaultScope = null;
    if (!empty($issue->components)) {
        $defaultScope = $issue->components[0]; // Use the first component name
        $scopePrompt = "Scope (auto-detected '{$defaultScope}')";
    }

    $type = io()->ask("Commit Type (auto-detected '{$detectedType}')", $detectedType);
    $scope = io()->ask($scopePrompt, $defaultScope);
    $summary = io()->ask("Short Message (auto-filled from Jira)", $detectedSummary);

    // 5. Assemble commit message according to the new template
    $commitMessage = "{$type}" . ($scope ? "({$scope})" : "") . ": {$summary} [{$key}]";

    io()->text('Staging all changes...');
    _run_process('git add -A');

    io()->text('Committing...');
    _run_process('git commit -m ' . escapeshellarg($commitMessage));

    io()->success('Commit created successfully!');
}

#[AsTask(name: 'please', aliases: ['pl'], description: 'A power-user, safe force-push (force-with-lease)')]
function please(): void
{
    io()->warning('⚠️  Forcing with lease...');
    // Use run() from castor for direct output streaming
    run('git push --force-with-lease');
}

#[AsTask(name: 'status', aliases: ['ss'], description: 'A quick "where am I?" dashboard')]
function status(): void
{
    io()->section('Current Status');
    $key = _get_key_from_branch();
    $branch = trim(_run_process('git rev-parse --abbrev-ref HEAD')->getOutput());

    // Jira Status
    if ($key) {
        $jira = _get_jira_service();
        try {
            $issue = $jira->getIssue($key);
            io()->writeln("Jira:   <fg=yellow>[{$issue->status}]</> {$issue->key}: {$issue->title}");
        } catch (\Exception $e) {
            io()->writeln("Jira:   <fg=red>Could not fetch Jira issue details: {$e->getMessage()}</>");
        }
    } else {
        io()->writeln("Jira:   <fg=gray>No Jira key found in branch name.</>");
    }

    // Git Status
    io()->writeln("Git:    On branch <fg=cyan>'{$branch}'</>");

    // Local Status
    $gitStatus = _run_process('git status --porcelain')->getOutput();
    $changeCount = count(array_filter(explode("\n", $gitStatus)));

    if ($changeCount > 0) {
        io()->writeln("Local:  You have <fg=red>{$changeCount} uncommitted changes.</>");
    } else {
        io()->writeln("Local:  <fg=green>Working directory is clean.</>");
    }
}
