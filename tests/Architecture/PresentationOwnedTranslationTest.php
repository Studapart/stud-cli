<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PresentationOwnedTranslationTest extends TestCase
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
    public function testNonPresentationLayersDoNotTranslateDirectly(string $filePath): void
    {
        $contents = file_get_contents($filePath);
        self::assertIsString($contents);

        self::assertStringNotContainsString('->trans(', $contents, $filePath);
        self::assertStringNotContainsString('TranslationService', $contents, $filePath);
    }
}
