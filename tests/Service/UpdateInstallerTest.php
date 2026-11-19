<?php

namespace App\Tests\Service;

use App\DTO\WorkflowRecorder;
use App\Service\FileSystem;
use App\Service\PharVersionActivator;
use App\Service\PortableUpdateService;
use App\Service\Prompt\PromptInterface;
use App\Service\UpdateFileService;
use App\Service\UpdateInstaller;
use App\Service\UpdateRepositoryContext;
use App\Tests\Support\RestoresServerHome;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UpdateInstallerTest extends TestCase
{
    use RestoresServerHome;

    private string $workspace;
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/update-installer-test-' . bin2hex(random_bytes(4));
        $this->home = $this->workspace . '/home';
        mkdir($this->home, 0777, true);
        $this->pointHomeAt($this->home);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        $this->restoreServerHome();

        parent::tearDown();
    }

    public function testPortableServiceBuildsPortableUpdateServiceForRepository(): void
    {
        $installer = $this->createInstaller($this->createStub(PromptInterface::class));

        $service = $installer->portableService(new UpdateRepositoryContext('owner', 'repo', 'token'));

        $this->assertInstanceOf(PortableUpdateService::class, $service);
    }

    public function testQuietUpdateAsksNothingAndWritesNoMarker(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects(self::never())->method('confirm');

        $this->createInstaller($prompt)->requestCleanup(new WorkflowRecorder(), true);

        self::assertFileDoesNotExist($this->home . '/.local/share/stud-portable/.cleanup-pending');
    }

    public function testDecliningCleanupWritesNoMarker(): void
    {
        $prompt = $this->createStub(PromptInterface::class);
        $prompt->method('confirm')->willReturn(false);

        $this->createInstaller($prompt)->requestCleanup(new WorkflowRecorder(), false);

        self::assertFileDoesNotExist($this->home . '/.local/share/stud-portable/.cleanup-pending');
    }

    public function testAcceptingCleanupWritesTheMarkerAndNotesIt(): void
    {
        $prompt = $this->createStub(PromptInterface::class);
        $prompt->method('confirm')->willReturn(true);
        $recorder = new WorkflowRecorder();

        $this->createInstaller($prompt)->requestCleanup($recorder, false);

        self::assertFileExists($this->home . '/.local/share/stud-portable/.cleanup-pending');
        self::assertSame(['note'], array_map(static fn ($entry): string => $entry->type, $recorder->getEntries()));
    }

    public function testFailingToRecordCleanupWarnsWithoutWritingAMarker(): void
    {
        mkdir($this->home . '/.local/share', 0777, true);
        file_put_contents($this->home . '/.local/share/stud-portable', 'not a directory');
        $prompt = $this->createStub(PromptInterface::class);
        $prompt->method('confirm')->willReturn(true);
        $recorder = new WorkflowRecorder();

        $this->createInstaller($prompt)->requestCleanup($recorder, false);

        self::assertSame(['warning'], array_map(static fn ($entry): string => $entry->type, $recorder->getEntries()));
    }

    public function testPharActivatorIsBuiltForTheManagedLayout(): void
    {
        $activator = $this->createInstaller($this->createStub(PromptInterface::class))->pharActivator();

        $this->assertInstanceOf(PharVersionActivator::class, $activator);
    }

    private function createInstaller(PromptInterface $prompt): UpdateInstaller
    {
        return new UpdateInstaller(
            $this->createStub(UpdateFileService::class),
            $prompt,
            $this->createStub(FileSystem::class),
            $this->createStub(HttpClientInterface::class),
        );
    }

    private function removeTree(string $path): void
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
                $this->removeTree($path . '/' . $item);
            }
        }
        rmdir($path);
    }
}
