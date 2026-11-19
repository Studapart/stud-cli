<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\WorkflowRecorder;
use App\Service\ManagedInstallPaths;
use App\Service\ManagedSymlinkSwitcher;
use App\Service\OldVersionCleaner;
use App\Service\PortableUpdateService;
use App\Service\TranslationService;
use App\Service\UpdateInstallContext;
use App\Service\UpdateRepositoryContext;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class PortableUpdateServiceTest extends TestCase
{
    private string $workspace;
    private string $portableRoot;
    private string $managedSymlink;
    private HttpClientInterface $httpClient;
    private TranslationService&Stub $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->httpClient = $this->createStub(HttpClientInterface::class);
        $this->translator = $this->createStub(TranslationService::class);
        $this->translator->method('trans')->willReturnCallback(
            fn (string $key, array $parameters = []): string => strtr($key, array_map('strval', $parameters))
        );
        $this->bindSubject();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    public function testSuccessfulUpdateSwitchesSymlinkAndKeepsPreviousVersion(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $checksums = hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n";
        $this->mockDownloads(file_get_contents($archive) ?: '', $checksums);

        $result = $this->createService()->update($context, $this->releaseData(), new WorkflowRecorder());

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

        $result = $this->createService()->update($context, $this->releaseData(), new WorkflowRecorder());

        self::assertSame(1, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
        self::assertFileDoesNotExist($this->portableRoot . '/linux-amd64/1.0.1/stud');
    }

    public function testMissingChecksumEntryLeavesCurrentSymlinkActive(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  another-file.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), new WorkflowRecorder());

        self::assertSame(1, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
    }

    public function testSymlinkSwitchFailureReportsErrorAndKeepsCurrentVersionActive(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");
        $switcher = $this->createStub(ManagedSymlinkSwitcher::class);
        $switcher->method('switch')->willReturn(false);
        $service = new PortableUpdateService(
            new UpdateRepositoryContext('studapart', 'stud-cli'),
            new OldVersionCleaner(ManagedInstallPaths::fromHome($this->workspace . '/home')),
            $switcher,
            $this->httpClient,
        );
        $recorder = new WorkflowRecorder();

        $result = $service->update($context, $this->releaseData(), $recorder);

        self::assertSame(1, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
        self::assertSame(['text', 'error'], array_values(array_map(static fn ($entry): string => $entry->type, $recorder->getEntries())));
    }

    public function testUnexpectedReleaseVersionIsRefusedBeforeAnyPathIsBuilt(): void
    {
        $context = $this->createInstalledPortableContext();
        $recorder = new WorkflowRecorder();

        $result = $this->createService()->update($context, ['tag_name' => 'v../../escape', 'assets' => []], $recorder);

        self::assertSame(1, $result);
        self::assertDirectoryExists($this->portableRoot . '/linux-amd64/1.0.0');
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
        self::assertSame(['error'], array_values(array_map(static fn ($entry): string => $entry->type, $recorder->getEntries())));
    }

    public function testSmokeFailureLeavesCurrentSymlinkActive(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', false);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), new WorkflowRecorder());

        self::assertSame(1, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
    }

    public function testInvalidArchiveLeavesCurrentSymlinkActive(): void
    {
        $context = $this->createInstalledPortableContext();
        $archiveContent = 'not a tar archive';
        $this->mockDownloads($archiveContent, hash('sha256', $archiveContent) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), new WorkflowRecorder());

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

        $result = $this->createService()->update($context, $this->releaseData(), new WorkflowRecorder());

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

        $result = $this->createService()->update($context, $this->releaseData(), new WorkflowRecorder());

        self::assertSame(1, $result);
    }

    public function testInvalidPortableContextIsRejected(): void
    {
        $context = new UpdateInstallContext(UpdateInstallContext::MODE_PORTABLE, '1.0.0', '/tmp/stud.phar');

        $result = $this->createService()->update($context, $this->releaseData(), new WorkflowRecorder());

        self::assertSame(1, $result);
    }

    public function testMissingPortableAssetIsRejected(): void
    {
        $context = $this->createInstalledPortableContext();

        $result = $this->createService()->update($context, ['tag_name' => 'v1.0.1', 'assets' => []], new WorkflowRecorder());

        self::assertSame(1, $result);
    }

    public function testAssetWithoutIdIsReportedAsFailure(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $context = $this->createInstalledPortableContext();
        $release = [
            'tag_name' => 'v1.0.1',
            'assets' => [
                ['name' => 'stud-portable-1.0.1-linux-amd64.tar.gz'],
                ['id' => 2, 'name' => 'checksums.txt'],
            ],
        ];
        $this->httpClient->expects(self::never())->method('request');

        $result = $this->createService()->update($context, $release, new WorkflowRecorder());

        self::assertSame(1, $result);
    }

    public function testActivationCreatesMissingPlatformDirectory(): void
    {
        $context = $this->createBrokenSymlinkPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $this->mockDownloads(file_get_contents($archive) ?: '', hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n");

        $result = $this->createService()->update($context, $this->releaseData(), new WorkflowRecorder());

        self::assertSame(0, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.1/stud', readlink($this->managedSymlink));
    }

    public function testActivationFailsWhenBundleCannotBeMoved(): void
    {
        $context = $this->createInstalledPortableContext();
        $archive = $this->createPortableArchive('1.0.1', true);
        $checksums = hash_file('sha256', $archive) . "  stud-portable-1.0.1-linux-amd64.tar.gz\n";
        $this->mockDownloads(file_get_contents($archive) ?: '', $checksums);
        $service = new class (new UpdateRepositoryContext('studapart', 'stud-cli'), new OldVersionCleaner(ManagedInstallPaths::fromHome($this->workspace . '/home')), new ManagedSymlinkSwitcher(), $this->httpClient) extends PortableUpdateService {
            protected function moveDirectory(string $from, string $to): bool
            {
                return false;
            }
        };

        $result = $service->update($context, $this->releaseData(), new WorkflowRecorder());

        self::assertSame(1, $result);
        self::assertSame($this->portableRoot . '/linux-amd64/1.0.0/stud', readlink($this->managedSymlink));
    }

    public function testMoveDirectoryCopiesWhenRenameFailsAndRemovesSource(): void
    {
        $from = $this->workspace . '/move-from';
        $to = $this->workspace . '/move-to';
        mkdir($from);
        file_put_contents($from . '/payload', 'bundle');
        mkdir($to);
        file_put_contents($to . '/existing', 'keep');
        $service = $this->exposingMoveService();

        self::assertTrue($service->exposeMove($from, $to));
        self::assertFileDoesNotExist($from);
        self::assertFileExists($to . '/move-from/payload');
    }

    public function testMoveDirectoryReturnsFalseWhenCopyFails(): void
    {
        $service = $this->exposingMoveService();

        self::assertFalse($service->exposeMove($this->workspace . '/missing-source', $this->workspace . '/missing-dest'));
    }

    public function testCanCreateRealHttpClientWhenNoClientIsInjected(): void
    {
        $service = new class (new UpdateRepositoryContext('studapart', 'stud-cli', 'token'), new OldVersionCleaner(ManagedInstallPaths::fromHome($this->workspace . '/home')), new ManagedSymlinkSwitcher()) extends PortableUpdateService {
            public function exposedClient(): HttpClientInterface
            {
                return $this->client();
            }
        };

        self::assertInstanceOf(HttpClientInterface::class, $service->exposedClient());
    }

    protected function exposingMoveService(): PortableUpdateService
    {
        return new class (new UpdateRepositoryContext('studapart', 'stud-cli'), new OldVersionCleaner(ManagedInstallPaths::fromHome($this->workspace . '/home')), new ManagedSymlinkSwitcher()) extends PortableUpdateService {
            public function exposeMove(string $from, string $to): bool
            {
                return $this->moveDirectory($from, $to);
            }
        };
    }

    protected function createService(?OldVersionCleaner $cleaner = null): PortableUpdateService
    {
        return new PortableUpdateService(
            new UpdateRepositoryContext('studapart', 'stud-cli'),
            $cleaner ?? new OldVersionCleaner(ManagedInstallPaths::fromHome($this->workspace . '/home')),
            new ManagedSymlinkSwitcher(),
            $this->httpClient,
        );
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
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $archiveResponse = $this->createStub(ResponseInterface::class);
        $archiveResponse->method('getContent')->willReturn($archiveContent);
        $checksumsResponse = $this->createStub(ResponseInterface::class);
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

    private function bindSubject(): void
    {

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/portable-update-test-' . bin2hex(random_bytes(4));
        $this->portableRoot = $this->workspace . '/stud-portable';
        $this->managedSymlink = $this->workspace . '/home/.local/bin/stud';
        mkdir(dirname($this->managedSymlink), 0777, true);
    }
}
