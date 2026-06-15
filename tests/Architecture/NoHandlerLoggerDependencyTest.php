<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NoHandlerLoggerDependencyTest extends TestCase
{
    public function testHandlersDoNotDependOnConcreteLogger(): void
    {
        $violations = [];

        foreach (ArchitectureSourceScanner::phpFilesIn(['src/Handler']) as $relativePath => [$absolutePath]) {
            $contents = file_get_contents($absolutePath);
            if ($contents === false) {
                continue;
            }

            if (str_contains($contents, 'use App\\Service\\Logger;') || preg_match('/\bLogger\s+\$/', $contents) === 1) {
                $violations[] = $relativePath;
            }
        }

        $this->assertSame([], $violations);
    }
}
