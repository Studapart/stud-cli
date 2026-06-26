<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Guard\CapabilityDiscovery;
use App\Guard\CommandHandlerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * ADR-023 §6.2: integration handlers must wrap external failures in MessageRef, not raw exception text.
 */
final class IntegrationHandlerExceptionBoundaryTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const BANNED_PATTERNS = [
        '/::fatal\s*\(\s*\$e->getMessage\s*\(\s*\)\s*\)/',
        '/::error\s*\(\s*\$e->getMessage\s*\(\s*\)\s*\)/',
        '/Response::error\s*\(\s*\$e->getMessage\s*\(\s*\)\s*\)/',
    ];

    public function testIntegrationHandlersDoNotReturnRawExceptionMessagesAsErrors(): void
    {
        $violations = [];

        foreach (CommandHandlerRegistry::entries() as $entry) {
            $handlerClass = $entry['handler'];
            if ($handlerClass === null) {
                continue;
            }

            $capabilities = CapabilityDiscovery::fromClass($handlerClass);
            if (! $this->isIntegrationHandler($capabilities->all())) {
                continue;
            }

            $relativePath = 'src/Handler/' . (new \ReflectionClass($handlerClass))->getShortName() . '.php';
            $contents = file_get_contents($relativePath);
            if ($contents === false) {
                continue;
            }

            foreach (self::BANNED_PATTERNS as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = $relativePath . ' matches ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * @param list<class-string> $capabilities
     */
    private function isIntegrationHandler(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (str_contains($capability, 'WorkItemJiraAware')
                || str_contains($capability, 'WorkItemLinearAware')
                || str_contains($capability, 'ConfluenceAware')) {
                return true;
            }
        }

        return false;
    }
}
