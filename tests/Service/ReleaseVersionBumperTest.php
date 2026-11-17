<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\DTO\ReleaseDocumentPaths;
use App\DTO\WorkflowRecorder;
use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\ReleaseVersionBumper;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

class ReleaseVersionBumperTest extends TestCase
{
    use ProphecyTrait;

    private const COMPOSER_JSON = '/composer.json';
    private const CHANGELOG = '/CHANGELOG.md';
    private const README = '/README.md';

    private FlysystemFilesystem $flysystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flysystem = new FlysystemFilesystem(new InMemoryFilesystemAdapter());
    }

    public function testBumpUpdatesProjectFilesAndRecordsEachStep(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $gitRepository->run(['composer', 'update', '--lock'])->shouldBeCalledOnce();
        $gitRepository->run(['composer', 'dump-config'])->shouldBeCalledOnce();
        $this->writeProjectFiles(
            "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Feature X.\n\n## [1.1.0] - 2025-10-01\n",
            "<!-- release-version:start -->stud-1.1.0.phar<!-- release-version:end -->\n",
        );
        $recorder = new WorkflowRecorder();

        $this->bumper($gitRepository->reveal())->bump('1.2.0', $recorder);

        $this->assertSame('1.2.0', json_decode($this->flysystem->read(self::COMPOSER_JSON), true)['version']);
        $changelog = $this->flysystem->read(self::CHANGELOG);
        $this->assertStringContainsString("## [Unreleased]\n\n## [1.2.0] - " . date('Y-m-d') . "\n\n### Added", $changelog);
        $this->assertStringContainsString('## [1.1.0] - 2025-10-01', $changelog);
        $this->assertSame(
            "<!-- release-version:start -->stud-1.2.0.phar<!-- release-version:end -->\n",
            $this->flysystem->read(self::README),
        );
        $this->assertSame(
            ['release.updated_composer', 'release.updated_lock', 'release.dumped_config', 'release.updated_changelog', 'release.updated_readme'],
            array_map(static fn ($entry): ?string => $entry->message instanceof MessageRef ? $entry->message->key : null, $recorder->toResponse(0)->entries),
        );
    }

    public function testBumpFailsWhenComposerJsonIsUnreadable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read composer.json');

        $this->bumper()->bump('1.2.3', new WorkflowRecorder());
    }

    public function testBumpFailsWhenComposerJsonIsInvalid(): void
    {
        $this->flysystem->write(self::COMPOSER_JSON, 'invalid json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.json format');

        $this->bumper()->bump('1.2.3', new WorkflowRecorder());
    }

    public function testBumpFailsWhenChangelogIsUnreadable(): void
    {
        $this->flysystem->write(self::COMPOSER_JSON, json_encode(['version' => '1.0.0']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read CHANGELOG.md');

        $this->bumper()->bump('1.2.3', new WorkflowRecorder());
    }

    public function testBumpFailsWhenReadmeIsUnreadable(): void
    {
        $this->flysystem->write(self::COMPOSER_JSON, json_encode(['version' => '1.0.0']));
        $this->flysystem->write(self::CHANGELOG, "## [Unreleased]\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read README.md');

        $this->bumper()->bump('1.0.0', new WorkflowRecorder());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function readmeCases(): iterable
    {
        yield 'marked phar filename' => [
            "<!-- release-version:start -->Download stud-3.4.1.phar and stud-3.4.1.phar again.<!-- release-version:end -->\n",
            '3.5.0',
            "<!-- release-version:start -->Download stud-3.5.0.phar and stud-3.5.0.phar again.<!-- release-version:end -->\n",
        ];
        yield 'marked download url' => [
            "<!-- release-version:start -->https://github.com/Studapart/stud-cli/releases/download/v2.1.0/stud-2.1.0.phar<!-- release-version:end -->\n",
            '3.0.0',
            "<!-- release-version:start -->https://github.com/Studapart/stud-cli/releases/download/v3.0.0/stud-3.0.0.phar<!-- release-version:end -->\n",
        ];
        $unmarked = "https://github.com/Studapart/stud-cli/releases/download/v2.1.0/stud-2.1.0.phar\n";
        yield 'unmarked references unchanged' => [$unmarked, '3.0.0', $unmarked];
        $nonMatching = "Some text without version patterns. stud-1.2.phar invalid.\n";
        yield 'non-matching content unchanged' => [$nonMatching, '2.0.0', $nonMatching];
        $noVersion = "# Title\n\nNo version here.\n";
        yield 'no version references' => [$noVersion, '1.0.0', $noVersion];
    }

    #[DataProvider('readmeCases')]
    public function testBumpRewritesOnlyMarkedReadmeReleaseBlocks(string $readme, string $version, string $expected): void
    {
        $this->writeProjectFiles("## [Unreleased]\n", $readme);

        $this->bumper()->bump($version, new WorkflowRecorder());

        $this->assertSame($expected, $this->flysystem->read(self::README));
    }

    private function writeProjectFiles(string $changelog, string $readme): void
    {
        $this->flysystem->write(self::COMPOSER_JSON, json_encode(['version' => '1.0.0']));
        $this->flysystem->write(self::CHANGELOG, $changelog);
        $this->flysystem->write(self::README, $readme);
    }

    private function bumper(?GitRepository $gitRepository = null): ReleaseVersionBumper
    {
        return new ReleaseVersionBumper(
            $gitRepository ?? $this->createStub(GitRepository::class),
            new FileSystem($this->flysystem),
            new ReleaseDocumentPaths(self::COMPOSER_JSON, self::CHANGELOG, self::README),
        );
    }
}
