<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ProseAssertAllowListTest extends TestCase
{
    /**
     * Relative path => count of assertStringContainsString and assertStringNotContainsString.
     *
     * @var array<string, int>
     */
    private const ALLOWED = [
        'tests/Handler/ConfluencePushHandlerTest.php' => 1,
        'tests/Handler/ConfluenceShowHandlerTest.php' => 1,
        'tests/Handler/InitHandlerTest.php' => 1,
        'tests/Handler/ItemDownloadHandlerTest.php' => 2,
        'tests/Handler/PrCommentsHandlerTest.php' => 4,
        'tests/Handler/ReleaseHandlerTest.php' => 5,
        'tests/Handler/UpdateHandlerTest.php' => 1,
        'tests/Responder/BranchListResponderTest.php' => 5,
        'tests/Responder/FilterShowResponderTest.php' => 5,
        'tests/Responder/ItemListResponderTest.php' => 11,
        'tests/Responder/ItemShowResponderTest.php' => 3,
        'tests/Responder/PrCommentsResponderTest.php' => 4,
        'tests/Responder/SearchResponderTest.php' => 5,
    ];

    public function testProseAssertsInHandlerAndResponderTestsMatchAllowList(): void
    {
        $found = [];
        foreach (ArchitectureSourceScanner::phpFilesIn(['tests/Handler', 'tests/Responder']) as $relativePath => $paths) {
            $contents = file_get_contents($paths[0]);
            $count = preg_match_all('/assertString(?:Not)?ContainsString\(/', (string) $contents);
            if ($count > 0) {
                $found[$relativePath] = $count;
            }
        }
        ksort($found);

        self::assertSame(
            self::ALLOWED,
            $found,
            'Prose substring asserts in handler and responder tests must match the allowlist. '
            . 'A test should fail only when the behavior under test regresses (see CONVENTIONS.md § Assertion quality).'
        );
    }
}
