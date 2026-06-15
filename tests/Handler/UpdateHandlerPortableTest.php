<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\UpdateHandler;
use App\Service\ChangelogParser;
use App\Service\FileSystem;
use App\Service\PortableUpdateService;
use App\Service\Prompt\PromptInterface;
use App\Service\UpdateFileService;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class UpdateHandlerPortableTest extends CommandTestCase
{
    private string $workspace;
    private HttpClientInterface&MockObject $httpClient;
    private PortableUpdateService&MockObject $portableUpdateService;
    private PromptInterface&MockObject $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = dirname(__DIR__, 2) . '/.cursor/tmp/update-handler-portable-test-' . bin2hex(random_bytes(4));
        mkdir($this->workspace . '/home', 0777, true);
        $_SERVER['HOME'] = $this->workspace . '/home';
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->portableUpdateService = $this->createMock(PortableUpdateService::class);
        $this->prompt = $this->createMock(PromptInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        parent::tearDown();
    }

    public function testPortableInstallRoutesToPortableUpdateService(): void
    {
        $bundleRoot = $this->createPortableBundle('linux-amd64/1.0.0', true);
        $this->mockReleaseFetch();
        $this->portableUpdateService->expects(self::once())
            ->method('update')
            ->willReturn(0);

        $response = $this->createHandler($bundleRoot . '/app/stud.phar')->handle(false, true);

        self::assertSame(0, $response->exitCode);
    }

    public function testLegacyPortableInstallIsRejectedBeforePortableUpdate(): void
    {
        $bundleRoot = $this->createPortableBundle('linux-amd64', false);
        $this->mockReleaseFetch();
        $this->portableUpdateService->expects(self::never())->method('update');

        $response = $this->createHandler($bundleRoot . '/app/stud.phar')->handle(false, true);

        self::assertSame(1, $response->exitCode);
    }

    protected function createHandler(string $binaryPath): UpdateHandler
    {
        $service = $this->portableUpdateService;

        return new class (
            'studapart',
            'stud-cli',
            '1.0.0',
            $binaryPath,
            $this->translationService,
            $this->createMock(ChangelogParser::class),
            new UpdateFileService($this->translationService, $this->prompt),
            $this->prompt,
            $this->createMock(FileSystem::class),
            null,
            $this->httpClient,
            $service
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
                private readonly PortableUpdateService $portableUpdateService,
            ) {
                parent::__construct(
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
                    $httpClient
                );
            }

            protected function createPortableUpdateService(): PortableUpdateService
            {
                return $this->portableUpdateService;
            }
        };
    }

    protected function mockReleaseFetch(): void
    {
        $releaseResponse = $this->createMock(ResponseInterface::class);
        $releaseResponse->method('getStatusCode')->willReturn(200);
        $releaseResponse->method('toArray')->willReturn([
            'tag_name' => 'v1.0.1',
            'assets' => [],
        ]);
        $changelogResponse = $this->createMock(ResponseInterface::class);
        $changelogResponse->method('getStatusCode')->willReturn(404);
        $changelogResponse->method('getContent')->willReturn('{"message":"Not Found"}');
        $this->httpClient->expects(self::exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($releaseResponse, $changelogResponse);
    }

    protected function createPortableBundle(string $path, bool $withManifest): string
    {
        $bundleRoot = $this->workspace . '/stud-portable/' . $path;
        mkdir($bundleRoot . '/app', 0777, true);
        mkdir($bundleRoot . '/runtime', 0777, true);
        file_put_contents($bundleRoot . '/app/stud.phar', 'phar');
        file_put_contents($bundleRoot . '/runtime/php', 'php');
        file_put_contents($bundleRoot . '/stud', 'launcher');

        if ($withManifest) {
            file_put_contents($bundleRoot . '/.stud-portable.json', '{"installMode":"portable","version":"1.0.0","platform":"linux-amd64"}');
        }

        return $bundleRoot;
    }

    protected function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
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
