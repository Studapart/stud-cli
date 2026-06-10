<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PresentationOwnedTranslationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPresentationSourceFiles(): iterable
    {
        $root = dirname(__DIR__, 2);
        $allowedServiceFiles = [
            'src/Service/AgentModeSchemaGenerator.php',
            'src/Service/CommandReferenceGenerator.php',
            'src/Service/HelpService.php',
            'src/Service/MessageRenderer.php',
            'src/Service/ResponderHelper.php',
            'src/Service/TranslationService.php',
        ];

        foreach (['src/Handler', 'src/Service'] as $directory) {
            $path = $root . '/' . $directory;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = str_replace($root . '/', '', $file->getPathname());
                if (in_array($relativePath, $allowedServiceFiles, true)) {
                    continue;
                }

                yield $relativePath => [$file->getPathname()];
            }
        }
    }

    /**
     * @dataProvider nonPresentationSourceFiles
     */
    public function testNonPresentationLayersDoNotTranslateDirectly(string $filePath): void
    {
        $contents = file_get_contents($filePath);
        self::assertIsString($contents);

        self::assertStringNotContainsString('->trans(', $contents, $filePath);
        self::assertStringNotContainsString('TranslationService', $contents, $filePath);
    }
}
