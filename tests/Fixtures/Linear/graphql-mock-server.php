<?php

declare(strict_types=1);

/**
 * Minimal GraphQL fixture router for Linear integration tests (SCI-168).
 * Started via PHP built-in server; pair with STUD_LINEAR_GRAPHQL_BASE_URI.
 */

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
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
    str_contains($query, 'AssignedIssues') => 'issues-list.json',
    str_contains($query, 'TeamsList') => 'teams.json',
    str_contains($query, 'TeamStates') => 'team-states.json',
    str_contains($query, 'IssueUpdate') => 'issue-update.json',
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

readfile($path);
