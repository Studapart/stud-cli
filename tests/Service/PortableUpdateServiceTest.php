<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\WorkflowRecorder;
use App\Service\PortableOldVersionCleaner;
use App\Service\PortableUpdateService;
use App\Service\Prompt\PromptInterface;
use App\Service\TranslationService;
use App\Service\UpdateInstallContext;
use App\Service\UpdateRepositoryContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class PortableUpdateServiceTest extends TestCase
{
    private string $workspace;
    private string $portableRoot;
    private string $managedSymlink;
    private HttpClientInterface&MockObject $httpClient;
    private PromptInterface&MockObject $prompt;
    private TranslationService&MockObject $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/portable-update-test-' . bin2hex(random_bytes(4));
        $this->portableRoot = $this->workspace . '/stud-portable';
        $this->managedSymlink = $this->workspace . '/home/.local/bin/stud';
        mkdir(dirname($this->managedSymlink), 0777, true);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->prompt = $this->createMock(PromptInterface::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturnCallback(
            fn (string $key, array $parameters = []): string => strtr($key, array_map('strval', $parameters))
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    public function testSuccessfulQuietUpdateSwitchesSymlinkAndKeepsPreviousVersion(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $checksums = hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n";
        $this->mockDownloads(file_get_contents($archive) ?: '', $checksums);
        $this->prompt->expects(self::never())->method('confirm');

        $result = $this->createService()->update($context, $this->releaseData(), true, new WorkflowRecorder());

        self::assertSame(0, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.1/stud', readlink($this->managedSymlink));
        self::assertFileExists($this->portableRoot . '/linux-amd64/1.0.0/stud');
        self::assertFileExists($this->portableRoot . '/linux-amd64/1.0.1/stud');
    }

    public function testChecksumFailureLeavesCurrentSymlinkActive(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', str_repeat('0', 64) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), true, new WorkflowRecorder());

        self::assertSame(1, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
        self::assertFileDoesNotExist($this->portableRoot . '/linux-amd64/1.0.1/stud');
    }

    public function testMissingChecksumEntryLeavesCurrentSymlinkActive(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  another-file.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), true, new WorkflowRecorder());

        self::assertSame(1, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
    }

    public function testInteractiveCleanupRemovesNonRunningVersionsImmediatelyAndDefersRunningBundle(): void
    {
        $context = $this->createInstalledPortableContext();
        $extraOld = $this->portableRoot . '/linux-amd64/0.9.0';
        mkdir($extraOld . '/app', 0777, true);
        $this->writeExecutable($extraOld . '/stud', "#!/usr/bin/env sh\nprintf 'old\\n'\n");
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");
        $this->prompt->expects(self::once())->method('confirm')->willReturn(true);

        $cleaner = $this->createCleanerWithDeferredCapture();
        $result = $this->createService($cleaner)->update($context, $this->releaseData(), false, new WorkflowRecorder());

        self::assertSame(0, $result);
        self::assertFileDoesNotExist($extraOld . '/stud');
        self::assertFileExists($this->portableRoot . '/linux-amd64/1.0.0/stud');
        self::assertFileExists($this->portableRoot . '/linux-amd64/1.0.1/stud');
        self::assertSame([$this->portableRoot . '/linux-amd64/1.0.0'], $cleaner->deferredPaths);
    }

    public function testInteractiveCleanupDoesNotDestroyRunningBundleBeforeReturn(): void
    {
        $context = $this->createInstalledPortableContext();
        $runningPhar = $context->bundleRoot . '/app/stud.phar';
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");
        $this->prompt->expects(self::once())->method('confirm')->willReturn(true);

        $cleaner = $this->createCleanerWithDeferredCapture();
        $result = $this->createService($cleaner)->update($context, $this->releaseData(), false, new WorkflowRecorder());

        self::assertSame(0, $result);
        self::assertFileExists($runningPhar);
        self::assertSame([$context->bundleRoot], $cleaner->deferredPaths);
    }

    public function testImmediateCleanupFailureEmitsWarningWithoutFailingUpdate(): void
    {
        $context = $this->createInstalledPortableContext();
        $extraOld = $this->portableRoot . '/linux-amd64/0.9.0';
        mkdir($extraOld . '/app', 0777, true);
        $this->writeExecutable($extraOld . '/stud', "#!/usr/bin/env sh\nprintf 'old\\n'\n");
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");
        $this->prompt->expects(self::once())->method('confirm')->willReturn(true);

        $recorder = new WorkflowRecorder();
        $cleaner = new class ($this->prompt, $extraOld) extends PortableOldVersionCleaner {
            public function __construct(PromptInterface $prompt, private readonly string $failPath)
            {
                parent::__construct($prompt);
            }

            protected function scheduleDeferredRemoval(array $paths): void
            {
            }

            public function removeDirectory(string $path): bool
            {
                if ($path === $this->failPath) {
                    return false;
                }

                return parent::removeDirectory($path);
            }
        };

        $result = $this->createService($cleaner)->update($context, $this->releaseData(), false, $recorder);

        self::assertSame(0, $result);
        $warnings = array_values(array_filter(
            $recorder->getEntries(),
            static fn ($entry): bool => $entry->type === 'warning'
        ));
        self::assertNotEmpty($warnings);
        $message = $warnings[0]->message;
        self::assertInstanceOf(\App\DTO\MessageRef::class, $message);
        self::assertSame('update.portable_cleanup_failed', $message->key);
        self::assertSame($extraOld, $message->parameters['path'] ?? null);
    }

    public function testDeferredScheduleFailureEmitsWarningWithoutFailingUpdate(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");
        $this->prompt->expects(self::once())->method('confirm')->willReturn(true);

        $recorder = new WorkflowRecorder();
        $cleaner = new class ($this->prompt) extends PortableOldVersionCleaner {
            protected function spawnDeferredRemoval(int $pid, array $paths): bool
            {
                return false;
            }
        };

        $result = $this->createService($cleaner)->update($context, $this->releaseData(), false, $recorder);

        self::assertSame(0, $result);
        self::assertFileExists($context->bundleRoot . '/app/stud.phar');
        $warnings = array_values(array_filter(
            $recorder->getEntries(),
            static fn ($entry): bool => $entry->type === 'warning'
        ));
        self::assertNotEmpty($warnings);
        $message = $warnings[0]->message;
        self::assertInstanceOf(\App\DTO\MessageRef::class, $message);
        self::assertSame('update.portable_cleanup_schedule_failed', $message->key);
    }

    public function testMissingProcessIdEmitsScheduleFailureWarning(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");
        $this->prompt->expects(self::once())->method('confirm')->willReturn(true);

        $recorder = new WorkflowRecorder();
        $cleaner = new class ($this->prompt) extends PortableOldVersionCleaner {
            protected function currentProcessId(): int|false
            {
                return false;
            }
        };

        $result = $this->createService($cleaner)->update($context, $this->releaseData(), false, $recorder);

        self::assertSame(0, $result);
        $warnings = array_values(array_filter(
            $recorder->getEntries(),
            static fn ($entry): bool => $entry->type === 'warning'
        ));
        self::assertNotEmpty($warnings);
        $message = $warnings[0]->message;
        self::assertInstanceOf(\App\DTO\MessageRef::class, $message);
        self::assertSame('update.portable_cleanup_schedule_failed', $message->key);
    }

    public function testSuccessfulDeferredScheduleEmitsNote(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");
        $this->prompt->expects(self::once())->method('confirm')->willReturn(true);

        $recorder = new WorkflowRecorder();
        $cleaner = new class ($this->prompt) extends PortableOldVersionCleaner {
            protected function spawnDeferredRemoval(int $pid, array $paths): bool
            {
                return true;
            }
        };

        $result = $this->createService($cleaner)->update($context, $this->releaseData(), false, $recorder);

        self::assertSame(0, $result);
        $notes = array_values(array_filter(
            $recorder->getEntries(),
            static fn ($entry): bool => $entry->type === 'note'
        ));
        self::assertNotEmpty($notes);
        $message = $notes[0]->message;
        self::assertInstanceOf(\App\DTO\MessageRef::class, $message);
        self::assertSame('update.portable_cleanup_deferred', $message->key);
    }

    public function testIsSameFilesystemPathFallsBackWhenRealpathFails(): void
    {
        $cleaner = new PortableOldVersionCleaner($this->prompt);
        $method = new \ReflectionMethod(PortableOldVersionCleaner::class, 'isSameFilesystemPath');
        $missing = $this->workspace . '/missing-a';
        $other = $this->workspace . '/missing-b';

        self::assertTrue($method->invoke($cleaner, $missing, $missing . '/'));
        self::assertFalse($method->invoke($cleaner, $missing, $other));
    }

    public function testRemoveDirectoryReturnsTrueForMissingPath(): void
    {
        $cleaner = new PortableOldVersionCleaner($this->prompt);
        self::assertTrue($cleaner->removeDirectory($this->workspace . '/does-not-exist'));
    }

    public function testSpawnDeferredRemovalBuildsDetachedCommandAndReturnsTrue(): void
    {
        $dir = $this->workspace . '/deferred-target';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/marker', 'x');

        $cleaner = new PortableOldVersionCleaner($this->prompt);
        $build = new \ReflectionMethod(PortableOldVersionCleaner::class, 'buildDeferredRemovalCommand');
        $command = $build->invoke($cleaner, 2147483646, [$dir]);
        self::assertStringContainsString('nohup sh -c', $command);
        self::assertStringContainsString('rm -rf', $command);
        self::assertStringContainsString(escapeshellarg($dir), $command);

        $spawn = new \ReflectionMethod(PortableOldVersionCleaner::class, 'spawnDeferredRemoval');
        self::assertTrue($spawn->invoke($cleaner, 2147483646, [$dir]));
    }

    public function testScheduleDeferredRemovalNoopsForEmptyPathList(): void
    {
        $cleaner = new PortableOldVersionCleaner($this->prompt);
        $method = new \ReflectionMethod(PortableOldVersionCleaner::class, 'scheduleDeferredRemoval');
        $method->invoke($cleaner, []);
        self::assertTrue(true);
    }

    public function testRemoveDirectoryPropagatesChildFailure(): void
    {
        $parent = $this->workspace . '/parent-dir';
        $child = $parent . '/child';
        mkdir($child, 0777, true);

        $cleaner = new class ($this->prompt, $child) extends PortableOldVersionCleaner {
            public function __construct(PromptInterface $prompt, private readonly string $failPath)
            {
                parent::__construct($prompt);
            }

            public function removeDirectory(string $path): bool
            {
                if ($path === $this->failPath) {
                    return false;
                }

                return parent::removeDirectory($path);
            }
        };

        self::assertFalse($cleaner->removeDirectory($parent));
        self::assertDirectoryExists($parent);
    }

    public function testSmokeFailureLeavesCurrentSymlinkActive(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', false);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), true, new WorkflowRecorder());

        self::assertSame(1, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
    }

    public function testInvalidArchiveLeavesCurrentSymlinkActive(): void
    {
        $context = $this->createInstalledPortableContext();
        $archiveContent = 'not a tar archive';
        $this->mockDownloads($archiveContent, hash('sha256', $archiveContent) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), true, new WorkflowRecorder());

        self::assertSame(1, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
    }

    public function testUnmanagedSymlinkIsRejectedWithoutSwitching(): void
    {
        $context = $this->createInstalledPortableContext();
        unlink($this->managedSymlink);
        $unmanaged = $this->workspace . '/elsewhere/stud';
        mkdir(dirname($unmanaged), 0777, true);
        $this->writeExecutable($unmanaged, "#!/usr/bin/env sh\nexit 0\n");
        symlink($unmanaged, $this->managedSymlink);
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), true, new WorkflowRecorder());

        self::assertSame(1, $result);
        self::assertSame($unmanaged, readlink($this->managedSymlink));
    }

    public function testLegacyPortableLayoutIsRejected(): void
    {
        $context = new UpdateInstallContext(
            UpdateInstallContext::MODE_PORTABLE,
            '1.0.0',
            $this->portableRoot . '/linux-amd64/app/stud.phar',
            'linux-amd64',
            $this->portableRoot . '/linux-amd64',
            $this->portableRoot,
            $this->portableRoot . '/linux-amd64/stud',
            $this->managedSymlink,
            true
        );

        $result = $this->createService()->update($context, $this->releaseData(), true, new WorkflowRecorder());

        self::assertSame(1, $result);
    }

    public function testInvalidPortableContextIsRejected(): void
    {
        $context = new UpdateInstallContext(UpdateInstallContext::MODE_PORTABLE, '1.0.0', '/tmp/stud.phar');

        $result = $this->createService()->update($context, $this->releaseData(), true, new WorkflowRecorder());

        self::assertSame(1, $result);
    }

    public function testMissingPortableAssetIsRejected(): void
    {
        $context = $this->createInstalledPortableContext();

        $result = $this->createService()->update($context, ['tag_name' => 'v1.0.1', 'assets' => []], true, new WorkflowRecorder());

        self::assertSame(1, $result);
    }

    public function testAssetWithoutIdIsReportedAsFailure(): void
    {
        $context = $this->createInstalledPortableContext();
        $release = [
            'tag_name' => 'v1.0.1',
            'assets' => [
                ['name' => 'stud-portable-1.0.1-linux-amd64.tar.gz'],
                ['id' => 2, 'name' => 'checksums.txt'],
            ],
        ];
        $this->httpClient->expects(self::never())->method('request');

        $result = $this->createService()->update($context, $release, true, new WorkflowRecorder());

        self::assertSame(1, $result);
    }

    public function testActivationCreatesMissingPlatformDirectory(): void
    {
        $context = $this->createBrokenSymlinkPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), true, new WorkflowRecorder());

        self::assertSame(0, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.1/stud', readlink($this->managedSymlink));
    }

    public function testCanCreateRealHttpClientWhenNoClientIsInjected(): void
    {
        $service = new class (new UpdateRepositoryContext('studapart', 'stud-cli', 'token'), $this->translator, $this->prompt) extends PortableUpdateService {
            public function exposedClient(): HttpClientInterface
            {
                return $this->client();
            }
        };

        self::assertInstanceOf(HttpClientInterface::class, $service->exposedClient());
    }

    protected function createService(?PortableOldVersionCleaner $cleaner = null): PortableUpdateService
    {
        return new PortableUpdateService(
            new UpdateRepositoryContext('studapart', 'stud-cli'),
            $this->translator,
            $this->prompt,
            $this->httpClient,
            $cleaner
        );
    }

    /**
     * @return PortableOldVersionCleaner&object{deferredPaths: list<string>}
     */
    protected function createCleanerWithDeferredCapture(): PortableOldVersionCleaner
    {
        return new class ($this->prompt) extends PortableOldVersionCleaner {
            /** @var list<string> */
            public array $deferredPaths = [];

            protected function scheduleDeferredRemoval(array $paths): void
            {
                $this->deferredPaths = $paths;
            }
        };
    }

    protected function createInstalledPortableContext(): UpdateInstallContext
    {
        $bundleRoot = $this->portableRoot . '/linux-amd64/1.0.0';
        mkdir($bundleRoot . '/app', 0777, true);
        mkdir($bundleRoot . '/runtime', 0777, true);
        file_put_contents($bundleRoot . '/app/stud.phar', 'old phar');
        $this->writeExecutable($bundleRoot . '/runtime/php', "#!/usr/bin/env sh\nexit 0\n");
        $this->writeExecutable($bundleRoot . '/stud', "#!/usr/bin/env sh\nprintf 'stud 1.0.0\\n'\n");
        symlink($bundleRoot . '/stud', $this->managedSymlink);

        return new UpdateInstallContext(
            UpdateInstallContext::MODE_PORTABLE,
            '1.0.0',
            $bundleRoot . '/app/stud.phar',
            'linux-amd64',
            $bundleRoot,
            $this->portableRoot,
            $bundleRoot . '/stud',
            $this->managedSymlink
        );
    }

    protected function createBrokenSymlinkPortableContext(): UpdateInstallContext
    {
        $bundleRoot = $this->portableRoot . '/linux-amd64/1.0.0';
        symlink($bundleRoot . '/stud', $this->managedSymlink);

        return new UpdateInstallContext(
            UpdateInstallContext::MODE_PORTABLE,
            '1.0.0',
            $bundleRoot . '/app/stud.phar',
            'linux-amd64',
            $bundleRoot,
            $this->portableRoot,
            $bundleRoot . '/stud',
            $this->managedSymlink
        );
    }

    protected function createPortableArchive(string $version, bool $workingLauncher): string
    {
        $artifactName = "stud-portable-{$version}-linux-amd64";
        $artifactRoot = $this->workspace . '/archives/' . $artifactName;
        mkdir($artifactRoot . '/app', 0777, true);
        mkdir($artifactRoot . '/runtime', 0777, true);
        file_put_contents($artifactRoot . '/app/stud.phar', 'new phar');
        $this->writeExecutable($artifactRoot . '/runtime/php', "#!/usr/bin/env sh\nexit 0\n");
        $launcher = $workingLauncher
            ? "#!/usr/bin/env sh\nprintf 'stud {$version}\\n'\n"
            : "#!/usr/bin/env sh\nexit 44\n";
        $this->writeExecutable($artifactRoot . '/stud', $launcher);

        $archivePath = $this->workspace . "/{$artifactName}.tar.gz";
        $process = new Process(['tar', '-czf', $archivePath, '-C', dirname($artifactRoot), $artifactName]);
        $process->mustRun();

        return $archivePath;
    }

    protected function mockDownloads(string $archiveContent, string $checksumsContent): void
    {
        $archiveResponse = $this->createMock(ResponseInterface::class);
        $archiveResponse->method('getContent')->willReturn($archiveContent);
        $checksumsResponse = $this->createMock(ResponseInterface::class);
        $checksumsResponse->method('getContent')->willReturn($checksumsContent);
        $this->httpClient->expects(self::exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($archiveResponse, $checksumsResponse);
    }

    /**
     * @return array<string, mixed>
     */
    protected function releaseData(): array
    {
        return [
            'tag_name' => 'v1.0.1',
            'assets' => [
                ['id' => 1, 'name' => 'stud-portable-1.0.1-linux-amd64.tar.gz'],
                ['id' => 2, 'name' => 'checksums.txt'],
            ],
        ];
    }

    protected function writeExecutable(string $path, string $content): void
    {
        file_put_contents($path, $content);
        chmod($path, 0755);
    }

    protected function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        self::assertIsArray($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath) && ! is_link($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
