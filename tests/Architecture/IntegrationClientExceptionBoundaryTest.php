<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ADR-023 §6.2: HTTP/GraphQL clients throw ApiException with English text, not MessageRef keys.
 */
final class IntegrationClientExceptionBoundaryTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const CLIENT_FILES = [
        'src/Service/JiraApiClient.php',
        'src/Service/JiraAttachmentService.php',
        'src/Service/LinearApiClient.php',
        'src/Service/LinearGraphqlClient.php',
        'src/Service/ConfluenceApiClient.php',
        'src/Service/GithubGitHostingAdapter.php',
        'src/Service/GitLabGitHostingAdapter.php',
    ];

    public function testIntegrationClientsDoNotReferenceMessageRef(): void
    {
        $violations = [];

        foreach (self::CLIENT_FILES as $relativePath) {
            $contents = file_get_contents($relativePath);
            if ($contents === false) {
                $violations[] = $relativePath . ' could not be read';

                continue;
            }

            if (str_contains($contents, 'MessageRef')) {
                $violations[] = $relativePath . ' references MessageRef';
            }
        }

        $this->assertSame([], $violations);
    }
}
