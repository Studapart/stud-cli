<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\UpdateHandler;
use App\Service\ChangelogParser;
use App\Service\FileSystem;
use App\Service\Prompt\PromptInterface;
use App\Service\UpdateFileService;
use App\Tests\CommandTestCase;
use App\Tests\Support\RestoresServerHome;
use App\Tests\Support\UpdateHandlerTestKit;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class UpdateHandlerManagedPharTest extends CommandTestCase
{
    use RestoresServerHome;

    private string $workspace;
    private string $download;
    private string $activePhar;
    private string $managedSymlink;
    private HttpClientInterface&MockObject $httpClient;
    private PromptInterface&Stub $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/update-handler-managed-phar-test-' . bin2hex(random_bytes(4));
        $home = $this->workspace . '/home';
        $this->pointHomeAt($home);
        $this->activePhar = $home . '/.local/share/stud-portable/phar/1.0.0-stud.phar';
        $this->managedSymlink = $home . '/.local/bin/stud';
        mkdir(dirname($this->activePhar), 0777, true);
        mkdir(dirname($this->managedSymlink), 0777, true);
        file_put_contents($this->activePhar, 'old phar');
        symlink($this->activePhar, $this->managedSymlink);

        $this->download = $this->workspace . '/stud.phar.download';
        file_put_contents($this->download, 'new phar');

        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->prompt = $this->createStub(PromptInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        $this->restoreServerHome();

        parent::tearDown();
    }

    public function testManagedPharUpdateSwitchesTheSymlinkAndKeepsTheRunningCopy(): void
    {
        $this->mockReleaseFetch(hash_file('sha256', $this->download));

        $response = $this->createHandler($this->activePhar)->handle(false, true);

        self::assertSame(0, $response->exitCode);
        self::assertSame($this->versionedPhar('1.0.1'), readlink($this->managedSymlink));
        self::assertSame('new phar', file_get_contents($this->versionedPhar('1.0.1')));
        self::assertSame('old phar', file_get_contents($this->activePhar));
    }

    public function testDecliningCleanupWritesNoMarker(): void
    {
        $this->mockReleaseFetch(hash_file('sha256', $this->download));
        $this->prompt->method('confirm')->willReturn(false);

        $response = $this->createHandler($this->activePhar)->handle(false, false);

        self::assertSame(0, $response->exitCode);
        self::assertFileDoesNotExist($this->cleanupMarker());
    }

    public function testAcceptingCleanupWritesTheMarker(): void
    {
        $this->mockReleaseFetch(hash_file('sha256', $this->download));
        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->createHandler($this->activePhar)->handle(false, false);

        self::assertSame(0, $response->exitCode);
        self::assertFileExists($this->cleanupMarker());
    }

    public function testQuietUpdateNeverAsksAndWritesNoMarker(): void
    {
        $this->mockReleaseFetch(hash_file('sha256', $this->download));
        $this->prompt = $this->createMock(PromptInterface::class);
        $this->prompt->expects(self::never())->method('confirm');

        $response = $this->createHandler($this->activePhar)->handle(false, true);

        self::assertSame(0, $response->exitCode);
        self::assertFileDoesNotExist($this->cleanupMarker());
    }

    public function testHashMismatchStopsBeforeAnyChangeOnDisk(): void
    {
        $this->mockReleaseFetch('0000');
        $this->prompt->method('confirm')->willReturn(false);

        $response = $this->createHandler($this->activePhar)->handle(false, false);

        self::assertSame(1, $response->exitCode);
        self::assertSame($this->activePhar, readlink($this->managedSymlink));
        self::assertFileDoesNotExist($this->versionedPhar('1.0.1'));
        self::assertFileDoesNotExist($this->cleanupMarker());
    }

    public function testMigrationFailureLeavesTheSymlinkAndWritesNothing(): void
    {
        $this->mockReleaseFetch(hash_file('sha256', $this->download));
        $this->prompt->method('confirm')->willReturn(true);

        $response = $this->createHandler($this->activePhar, 1)->handle(false, false);

        self::assertSame(1, $response->exitCode);
        self::assertSame($this->activePhar, readlink($this->managedSymlink));
        self::assertFileDoesNotExist($this->versionedPhar('1.0.1'));
        self::assertFileDoesNotExist($this->cleanupMarker());
    }

    public function testFailedActivationNeverAsksAboutCleanup(): void
    {
        $this->mockReleaseFetch(hash_file('sha256', $this->download));
        $foreign = $this->workspace . '/foreign.phar';
        file_put_contents($foreign, 'foreign phar');
        unlink($this->managedSymlink);
        symlink($foreign, $this->managedSymlink);
        $this->prompt = $this->createMock(PromptInterface::class);
        $this->prompt->expects(self::never())->method('confirm');

        $response = $this->createHandler($this->activePhar)->handle(false, false);

        self::assertSame(1, $response->exitCode);
        self::assertSame($foreign, readlink($this->managedSymlink));
        self::assertFileDoesNotExist($this->cleanupMarker());
    }

    public function testPharOutsideTheManagedPathIsReplacedInPlaceWithoutCleanup(): void
    {
        $this->mockReleaseFetch(hash_file('sha256', $this->download));
        $this->prompt->method('confirm')->willReturn(true);
        $standalone = $this->workspace . '/elsewhere/stud.phar';
        mkdir(dirname($standalone), 0777, true);
        file_put_contents($standalone, 'standalone phar');

        $response = $this->createHandler($standalone)->handle(false, false);

        self::assertSame(0, $response->exitCode);
        self::assertSame('new phar', file_get_contents($standalone));
        self::assertFileDoesNotExist($this->versionedPhar('1.0.1'));
        self::assertFileDoesNotExist($this->cleanupMarker());
    }

    private function cleanupMarker(): string
    {
        return dirname($this->activePhar, 2) . '/.cleanup-pending';
    }

    private function versionedPhar(string $version): string
    {
        return dirname($this->activePhar) . '/' . $version . '-stud.phar';
    }

    protected function createHandler(string $binaryPath, int $migrationExitCode = 0): UpdateHandler
    {
        $download = $this->download;

        return new class (
            'studapart',
            'stud-cli',
            '1.0.0',
            $binaryPath,
            $this->translationService,
            $this->createStub(ChangelogParser::class),
            new UpdateFileService($this->translationService, $this->prompt),
            $this->prompt,
            $this->createStub(FileSystem::class),
            null,
            $this->httpClient,
            $download,
            $migrationExitCode,
        ) extends UpdateHandler {
            public function __construct(
                string $repoOwner,
                string $repoName,
                string $currentVersion,
                string $binaryPath,
                \App\Service\TranslationService $translator,
                ChangelogParser $changelogParser,
                UpdateFileService $updateFileService,
                PromptInterface $prompt,
                FileSystem $fileSystem,
                ?string $gitToken,
                ?HttpClientInterface $httpClient,
                private readonly string $downloadPath,
                private readonly int $migrationExitCode,
            ) {
                parent::__construct(...UpdateHandlerTestKit::parts(
                    $repoOwner,
                    $repoName,
                    $currentVersion,
                    $binaryPath,
                    $translator,
                    $changelogParser,
                    $updateFileService,
                    $prompt,
                    $fileSystem,
                    $gitToken,
                    $httpClient,
                ));
            }

            protected function downloadPhar(array $pharAsset): ?string
            {
                return $this->downloadPath;
            }

            protected function runPrerequisiteMigrations(): int
            {
                return $this->migrationExitCode;
            }
        };
    }

    protected function mockReleaseFetch(string $sha256): void
    {
        $releaseResponse = $this->createStub(ResponseInterface::class);
        $releaseResponse->method('getStatusCode')->willReturn(200);
        $releaseResponse->method('toArray')->willReturn([
            'tag_name' => 'v1.0.1',
            'assets' => [
                ['id' => 10, 'name' => 'stud.phar', 'digest' => 'sha256:' . $sha256],
            ],
        ]);
        $changelogResponse = $this->createStub(ResponseInterface::class);
        $changelogResponse->method('getStatusCode')->willReturn(404);
        $changelogResponse->method('getContent')->willReturn('{"message":"Not Found"}');
        $this->httpClient->expects(self::exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($releaseResponse, $changelogResponse);
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeDirectory($path . '/' . $item);
            }
        }
        rmdir($path);
    }
}
