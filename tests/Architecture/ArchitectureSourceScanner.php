<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

final class ArchitectureSourceScanner
{
    /**
     * @param list<string> $relativeDirectories e.g. ['src/Handler', 'src/Service']
     * @param list<string> $excludedRelativePaths paths relative to project root
     *
     * @return iterable<string, array{string}>
     */
    public static function phpFilesIn(array $relativeDirectories, array $excludedRelativePaths = []): iterable
    {
        $root = dirname(__DIR__, 2);

        foreach ($relativeDirectories as $directory) {
            $path = $root . '/' . $directory;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = str_replace($root . '/', '', $file->getPathname());
                if (in_array($relativePath, $excludedRelativePaths, true)) {
                    continue;
                }

                yield $relativePath => [$file->getPathname()];
            }
        }
    }

    /**
     * @return list<string> sorted relative paths
     */
    public static function filesUsingWorkflowOutput(string $relativeDirectory): array
    {
        $matches = [];

        foreach (self::phpFilesIn([$relativeDirectory]) as $relativePath => [$absolutePath]) {
            if (str_ends_with($relativePath, '/WorkflowOutput.php')) {
                continue;
            }

            $contents = file_get_contents($absolutePath);
            if ($contents === false || ! self::usesWorkflowOutput($contents)) {
                continue;
            }

            $matches[] = $relativePath;
        }

        sort($matches);

        return $matches;
    }

    public static function usesWorkflowOutput(string $contents): bool
    {
        return str_contains($contents, 'use App\\Service\\WorkflowOutput;')
            || preg_match('/\bWorkflowOutput\s+\$/', $contents) === 1
            || preg_match('/\?WorkflowOutput\s+\$/', $contents) === 1
            || preg_match('/new\s+(?:\\\\App\\\\Service\\\\)?WorkflowOutput\s*\(/', $contents) === 1;
    }

    public static function containsDirectConsoleOutputCall(string $contents): bool
    {
        return preg_match(
            '/(\$io|\$this->(?:io|outputBuffer|buffer))\s*->\s*(error|warning|note|success|text|writeln|jiraWriteln|gitWriteln|section|title|rawValue|comment|info|caution|listing|table|horizontalTable|definitionList)\s*\(/',
            $contents
        ) === 1;
    }
}
