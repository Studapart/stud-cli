<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * One-off script to list Jira issue types for a project.
 * Usage: php scripts/list-jira-issue-types.php <projectKey>
 * Uses ~/.config/stud/config.yml for JIRA_URL, JIRA_EMAIL, JIRA_API_TOKEN.
 */

$projectKey = $argv[1] ?? null;
if ($projectKey === null || $projectKey === '') {
    fwrite(STDERR, "Usage: php scripts/list-jira-issue-types.php <projectKey>\n");
    exit(1);
}

$home = getenv('HOME');
if ($home === false || $home === '') {
    fwrite(STDERR, "HOME not set.\n");
    exit(1);
}

$configPath = $home . '/.config/stud/config.yml';
if (!is_readable($configPath)) {
    fwrite(STDERR, "Config not found or not readable: {$configPath}\n");
    exit(1);
}

$config = Symfony\Component\Yaml\Yaml::parseFile($configPath);
$required = ['JIRA_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN'];
foreach ($required as $key) {
    if (empty($config[$key])) {
        fwrite(STDERR, "Missing config key: {$key}\n");
        exit(1);
    }
}

$baseUrl = rtrim($config['JIRA_URL'], '/');
$auth = base64_encode($config['JIRA_EMAIL'] . ':' . $config['JIRA_API_TOKEN']);
$url = $baseUrl . '/rest/api/3/issue/createmeta/' . $projectKey . '/issuetypes';

$ch = curl_init($url);
if ($ch === false) {
    fwrite(STDERR, "curl_init failed\n");
    exit(1);
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Basic ' . $auth,
        'User-Agent: stud-cli',
    ],
]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    fwrite(STDERR, "Request failed\n");
    exit(1);
}
if ($status !== 200) {
    fwrite(STDERR, "HTTP {$status}: " . substr($response, 0, 500) . "\n");
    exit(1);
}

$data = json_decode($response, true);
$values = $data['values'] ?? $data['issueTypes'] ?? $data['issuetypes'] ?? (is_array($data) && array_is_list($data) ? $data : null);
if (!is_array($values)) {
    fwrite(STDERR, "Unexpected response format. Top-level keys: " . implode(', ', array_keys($data ?? [])) . "\n");
    exit(1);
}

echo "Issue types for project {$projectKey}:\n";
foreach ($values as $item) {
    $id = $item['id'] ?? '';
    $name = $item['name'] ?? '';
    echo "  - {$name} (id: {$id})\n";
}
