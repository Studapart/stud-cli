<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Verifies the jq merge algorithm used by .github/scripts/sync-pr-labels.sh
 * (jira-label-sync.yml and linear-label-sync.yml).
 */
class LabelSyncMergeAlgorithmTest extends TestCase
{
    private string $jqProgramPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jqProgramPath = dirname(__DIR__) . '/Fixtures/label-sync/merge-managed-labels.jq';
        self::assertFileExists($this->jqProgramPath);
    }

    /**
     * @return iterable<string, array{current: list<string>, pr: list<string>, map: array<string, string>, expected: list<string>}>
     */
    public static function mergeCasesProvider(): iterable
    {
        yield 'preserves unmanaged labels' => [
            'current' => ['Legacy', 'Bug'],
            'pr' => ['bug'],
            'map' => ['bug' => 'Bug'],
            'expected' => ['Bug', 'Legacy'],
        ];

        yield 'adds managed label when github label present' => [
            'current' => ['Legacy'],
            'pr' => ['enhancement'],
            'map' => ['enhancement' => 'Feature'],
            'expected' => ['Feature', 'Legacy'],
        ];

        yield 'removes managed label when no mapped github label on pr' => [
            'current' => ['Bug', 'Legacy'],
            'pr' => [],
            'map' => ['bug' => 'Bug'],
            'expected' => ['Legacy'],
        ];

        yield 'or group multiple github labels map to same target' => [
            'current' => [],
            'pr' => ['type-story'],
            'map' => ['type-story' => 'Story', 'story' => 'Story'],
            'expected' => ['Story'],
        ];

        yield 'no change when merged equals current' => [
            'current' => ['Bug', 'Legacy'],
            'pr' => ['bug'],
            'map' => ['bug' => 'Bug'],
            'expected' => ['Bug', 'Legacy'],
        ];
    }

    /**
     * @param list<string>              $current
     * @param list<string>              $pr
     * @param array<string, string>     $map
     * @param list<string>              $expected
     */
    #[DataProvider('mergeCasesProvider')]
    public function testMergeManagedLabelsMatchesWorkflowAlgorithm(
        array $current,
        array $pr,
        array $map,
        array $expected,
    ): void {
        $mapFile = tempnam(sys_get_temp_dir(), 'stud-label-map-');
        self::assertNotFalse($mapFile);
        file_put_contents($mapFile, json_encode($map, JSON_THROW_ON_ERROR));

        try {
            $process = new Process([
                'jq',
                '-cn',
                '--argjson',
                'current',
                json_encode($current, JSON_THROW_ON_ERROR),
                '--argjson',
                'prn',
                json_encode($pr, JSON_THROW_ON_ERROR),
                '--slurpfile',
                'm',
                $mapFile,
                '-f',
                $this->jqProgramPath,
            ]);
            $process->mustRun();
            $merged = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            unlink($mapFile);
        }

        self::assertSame($expected, $merged);
    }
}
