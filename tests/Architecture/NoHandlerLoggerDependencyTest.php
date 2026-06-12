<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NoHandlerLoggerDependencyTest extends TestCase
{
    public function testHandlersDoNotDependOnConcreteLogger(): void
    {
        $handlerDir = dirname(__DIR__, 2) . '/src/Handler';
        $violations = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($handlerDir)) as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            if (str_contains($contents, 'use App\\Service\\Logger;') || preg_match('/\bLogger\s+\$/', $contents) === 1) {
                $violations[] = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $violations);
    }
}
