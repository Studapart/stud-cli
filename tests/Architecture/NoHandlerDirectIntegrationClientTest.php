<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Handlers must depend on outbound ports, not HTTP/GraphQL clients (ADR-023 §3, SCI-163).
 */
final class NoHandlerDirectIntegrationClientTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const BANNED_USE_STATEMENTS = [
        'use App\\Service\\JiraApiClient;',
        'use App\\Service\\JiraAttachmentService;',
        'use App\\Service\\LinearApiClient;',
        'use App\\Service\\ConfluenceApiClient;',
    ];

    public function testHandlersDoNotImportIntegrationClients(): void
    {
        $violations = [];

        foreach (ArchitectureSourceScanner::phpFilesIn(['src/Handler']) as $relativePath => [$absolutePath]) {
            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                continue;
            }

            foreach (self::BANNED_USE_STATEMENTS as $useStatement) {
                if (str_contains($contents, $useStatement)) {
                    $violations[] = $relativePath . ' imports ' . trim(str_replace('use ', '', $useStatement));
                }
            }
        }

        $this->assertSame([], $violations);
    }
}
