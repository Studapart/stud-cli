<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FileSystem;
use App\Service\FileSystemPathPolicy;
use App\Service\GitProjectConfigService;
use App\Service\GitRemoteUrlParser;
use App\Service\ProcessFactory;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GitProjectConfigServiceTest extends TestCase
{
    private ProcessFactory $processFactory;
    private GitRemoteUrlParser $remoteUrlParser;
    private FileSystem $fileSystem;
    private FlysystemFilesystem $flysystem;
    private GitProjectConfigService $service;

    protected function setUp(): void
    {
        $this->processFactory = $this->createStub(ProcessFactory::class);
        $this->remoteUrlParser = $this->createStub(GitRemoteUrlParser::class);
        $this->bindSubject();
    }

    public function testGetProjectKeyFromIssueKey(): void
    {
        $this->assertSame('PROJ', $this->service->getProjectKeyFromIssueKey('proj-42'));
    }

    public function testGetProjectKeyFromIssueKeyThrowsOnInvalidFormat(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid issue key format: bad');

        $this->service->getProjectKeyFromIssueKey('bad');
    }

    public function testGetGitProviderFromConfig(): void
    {
        $this->remoteUrlParser = $this->createMock(GitRemoteUrlParser::class);
        $this->bindSubject();

        $this->mockGitDir('/test/git-dir');
        $this->flysystem->write(
            '/test/git-dir/stud.config',
            \Symfony\Component\Yaml\Yaml::dump(['gitProvider' => 'gitlab'])
        );

        $this->remoteUrlParser->expects($this->never())->method('parseRemote');

        $this->assertSame('gitlab', $this->service->getGitProvider());
    }

    public function testGetGitProviderAutoDetectsFromRemote(): void
    {
        $this->remoteUrlParser = $this->createMock(GitRemoteUrlParser::class);
        $this->bindSubject();

        $this->mockGitDir('.git');
        $this->remoteUrlParser->expects($this->once())
            ->method('parseRemote')
            ->with('origin')
            ->willReturn(['provider' => 'github']);

        $this->assertSame('github', $this->service->getGitProvider());
    }

    public function testGetGitProviderReturnsNullWhenUnknown(): void
    {
        $this->remoteUrlParser = $this->createMock(GitRemoteUrlParser::class);
        $this->bindSubject();

        $this->mockGitDir('.git');
        $this->remoteUrlParser->expects($this->once())
            ->method('parseRemote')
            ->willReturn([]);

        $this->assertNull($this->service->getGitProvider());
    }

    public function testReadProjectConfigReturnsEmptyArrayWhenParseFails(): void
    {
        $this->processFactory = $this->createMock(ProcessFactory::class);
        $mockFileSystem = $this->createStub(FileSystem::class);
        $service = new GitProjectConfigService($this->processFactory, $mockFileSystem, $this->remoteUrlParser);

        $this->mockGitDir('.git');
        $mockFileSystem->method('fileExists')->willReturn(true);
        $mockFileSystem->method('parseFile')->willThrowException(new \RuntimeException('invalid yaml'));

        $this->assertSame([], $service->readProjectConfig());
        $this->assertTrue($service->readProjectConfigResult()->isUnreadable());
    }

    public function testReadProjectConfigResultReturnsReadableConfig(): void
    {
        $this->mockGitDir('.git');
        $this->flysystem->write(
            '.git/stud.config',
            \Symfony\Component\Yaml\Yaml::dump(['projectKey' => 'PROJ'])
        );

        $result = $this->service->readProjectConfigResult();

        $this->assertFalse($result->isUnreadable());
        $this->assertSame('PROJ', $result->config['projectKey']);
    }

    private function mockGitDir(string $gitDir): void
    {
        if (! $this->processFactory instanceof MockObject) {
            $this->processFactory = $this->createMock(ProcessFactory::class);
            $this->bindSubject();
        }

        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->atLeastOnce())
            ->method('create')
            ->with(['git', 'rev-parse', '--git-dir'])
            ->willReturn($process);

        $process->expects($this->atLeastOnce())->method('run');
        $process->expects($this->atLeastOnce())->method('isSuccessful')->willReturn(true);
        $process->expects($this->atLeastOnce())->method('getOutput')->willReturn($gitDir);
    }

    private function bindSubject(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $this->flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($this->flysystem, new FileSystemPathPolicy($this->flysystem));
        $this->service = new GitProjectConfigService($this->processFactory, $this->fileSystem, $this->remoteUrlParser);
    }
}
