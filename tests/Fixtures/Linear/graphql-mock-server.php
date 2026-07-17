<?php

declare(strict_types=1);

/**
 * Minimal GraphQL fixture router for Linear integration tests (SCI-168+).
 * Started via PHP built-in server; pair with STUD_LINEAR_GRAPHQL_BASE_URI.
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

if ($method === 'PUT' && str_starts_with($uri, '/upload/')) {
    header('Content-Type: text/plain');
    http_response_code(200);
    echo 'ok';

    return;
}

if ($method === 'GET' && str_starts_with($uri, '/assets/')) {
    header('Content-Type: text/plain');
    http_response_code(200);
    echo 'linear-file-bytes';

    return;
}

header('Content-Type: application/json');

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['errors' => [['message' => 'Method not allowed']]], JSON_THROW_ON_ERROR);

    return;
}

$raw = file_get_contents('php://input');
/** @var array<string, mixed>|null $body */
$body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$query = is_array($body) && isset($body['query']) && is_string($body['query']) ? $body['query'] : '';

$fixture = match (true) {
    str_contains($query, 'ViewerPing') => 'viewer-ping.json',
    str_contains($query, 'IssueShow') => 'issue-show.json',
    str_contains($query, 'IssueId') => 'issue-id.json',
    str_contains($query, 'CustomViews') => 'custom-views.json',
    str_contains($query, 'AssignedIssues') => 'issues-list.json',
    str_contains($query, 'TeamsList') => 'teams.json',
    str_contains($query, 'TeamStates') => 'team-states.json',
    str_contains($query, 'IssueUpdate') => 'issue-update.json',
    str_contains($query, 'FileUpload') => 'file-upload.json',
    str_contains($query, 'AttachmentCreate') => 'attachment-create.json',
    default => null,
};

if ($fixture === null) {
    http_response_code(404);
    echo json_encode(['errors' => [['message' => 'Unknown GraphQL operation']]], JSON_THROW_ON_ERROR);

    return;
}

$path = __DIR__ . '/' . $fixture;
if (! is_readable($path)) {
    http_response_code(500);
    echo json_encode(['errors' => [['message' => 'Fixture missing']]], JSON_THROW_ON_ERROR);

    return;
}

$contents = file_get_contents($path);
if ($contents === false) {
    http_response_code(500);
    echo json_encode(['errors' => [['message' => 'Fixture unreadable']]], JSON_THROW_ON_ERROR);

    return;
}

$base = getenv('MOCK_LINEAR_ASSET_BASE');
if (! is_string($base) || $base === '') {
    $host = $_SERVER['SERVER_NAME'] ?? '127.0.0.1';
    $port = $_SERVER['SERVER_PORT'] ?? '80';
    $base = 'http://' . $host . ':' . $port;
}

$contents = str_replace('MOCK_UPLOAD_URL', rtrim($base, '/') . '/upload/object', $contents);
$contents = str_replace('MOCK_ASSET_URL', rtrim($base, '/') . '/assets/report.md', $contents);

echo $contents;
