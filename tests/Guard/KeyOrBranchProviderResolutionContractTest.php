<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Enum\WorkItemCommandProfile;
use App\Guard\WorkItemCommandProfileRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Ensures KeyOrBranch commands pass a branch-derived issue key into provider resolution
 * under issueTrackerProvider: auto (regression: items:transition omitted the branch peek).
 */
final class KeyOrBranchProviderResolutionContractTest extends TestCase
{
    public function testEveryKeyOrBranchCommandWiresBranchKeyIntoProviderResolution(): void
    {
        $castorPath = dirname(__DIR__, 2) . '/castor.php';
        self::assertFileExists($castorPath);
        $castor = file_get_contents($castorPath);
        self::assertNotFalse($castor);

        self::assertStringContainsString(
            'function _issue_key_for_provider_resolution',
            $castor,
            'Shared helper must exist for optional-key commands that peek the branch.',
        );

        /** @var array<string, string> $functionByCommand */
        $functionByCommand = [
            'items:transition' => 'items_transition',
            'commit' => 'commit',
            'push' => 'push',
            'submit' => 'submit',
            'status' => 'status',
            'branch:rename' => 'branch_rename',
        ];

        foreach (WorkItemCommandProfileRegistry::inScopeCommandNames() as $command) {
            if (WorkItemCommandProfileRegistry::forCommand($command) !== WorkItemCommandProfile::KeyOrBranch) {
                continue;
            }

            self::assertArrayHasKey(
                $command,
                $functionByCommand,
                "Add castor function mapping for KeyOrBranch command {$command}.",
            );

            $functionName = $functionByCommand[$command];
            $pattern = '/function\s+' . preg_quote($functionName, '/') . '\s*\(/';
            self::assertMatchesRegularExpression($pattern, $castor, "Missing castor function {$functionName}.");

            $start = null;
            if (preg_match($pattern, $castor, $m, PREG_OFFSET_CAPTURE) === 1) {
                $start = (int) $m[0][1];
            }
            self::assertNotNull($start);
            $next = $this->nextFunctionOffset($castor, $start + 1);
            $body = substr($castor, $start, $next - $start);

            $wiresBranchKey = str_contains($body, '_issue_key_for_provider_resolution(')
                || str_contains($body, '_require_issue_tracker_for_git_workflow(')
                || str_contains($body, '_get_branch_rename_handler(')
                || str_contains($body, '_branch_issue_key(');

            self::assertTrue(
                $wiresBranchKey,
                "{$command} ({$functionName}) must pass branch issue key into provider resolution "
                . '(via _issue_key_for_provider_resolution, _require_issue_tracker_for_git_workflow, '
                . '_get_branch_rename_handler, or _branch_issue_key).',
            );
        }
    }

    private function nextFunctionOffset(string $source, int $after): int
    {
        if (preg_match('/\nfunction\s+\w+\s*\(/', $source, $m, PREG_OFFSET_CAPTURE, $after) === 1) {
            return (int) $m[0][1];
        }

        return strlen($source);
    }
}
