<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponderOwnedOutputTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPresentationSourceFiles(): iterable
    {
        return ArchitectureSourceScanner::phpFilesIn(
            ['src/Handler', 'src/Service'],
            ArchitecturePresentationExceptions::ALLOWED_SERVICE_FILES,
        );
    }

    #[DataProvider('nonPresentationSourceFiles')]
    public function testNonPresentationLayersDoNotRenderOutput(string $filePath): void
    {
        $contents = file_get_contents($filePath);
        self::assertIsString($contents);

        self::assertStringNotContainsString('use App\\Service\\Logger;', $contents, $filePath);
        self::assertStringNotContainsString('use App\\Service\\CommandOutputBuffer;', $contents, $filePath);
        self::assertFalse(
            ArchitectureSourceScanner::containsDirectConsoleOutputCall($contents),
            $filePath . ' must not call SymfonyStyle/console output methods directly'
        );
    }
}
