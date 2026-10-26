<?php

namespace App\Tests\Service;

use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\ProcessFactory;
use App\Tests\CommandTestCase;
use App\Tests\Support\GitRepositoryTestKit;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class GitRepositoryTest extends CommandTestCase
{
    protected GitRepository $gitRepository;
    private ProcessFactory&Stub $processFactory;
    protected FileSystem $fileSystem;
    protected FlysystemFilesystem $flysystem;

    protected function setUp(): void
    {
        parent::setUp();

        // Create in-memory filesystem
        $adapter = new InMemoryFilesystemAdapter();
        $this->flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($this->flysystem);

        $this->processFactory = $this->createStub(ProcessFactory::class);
        $this->gitRepository = GitRepositoryTestKit::create($this->processFactory, $this->fileSystem);
    }

    public function testGetIssueKeyFromBranchName(): void
    {
        $this->givenProcesses($this->stubProcess('feature/PROJ-123-my-feature'));


        $key = $this->gitRepository->getIssueKeyFromBranchName();

        $this->assertSame('PROJ-123', $key);
    }

    public function testGetIssueKeyFromBranchNameReturnsNullIfNotFound(): void
    {
        $this->givenProcesses($this->stubProcess('main'));


        $key = $this->gitRepository->getIssueKeyFromBranchName();

        $this->assertNull($key);
    }

    public function testGetIssueKeyFromBranchNameReturnsNullIfNotSuccessful(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $key = $this->gitRepository->getIssueKeyFromBranchName();

        $this->assertNull($key);
    }

    public function testGetIssueKeyFromBranchNameExtractsLinearIdentifierFromFeatBranch(): void
    {
        $this->givenProcesses($this->stubProcess('feat/SCI-123-linear-feature-slug'));


        $this->assertSame('SCI-123', $this->gitRepository->getIssueKeyFromBranchName());
    }

    public function testGetIssueKeyFromBranchNameExtractsMultiLetterLinearTeamKey(): void
    {
        $this->givenProcesses($this->stubProcess('fix/TEAM-456-bugfix-title'));


        $this->assertSame('TEAM-456', $this->gitRepository->getIssueKeyFromBranchName());
    }

    public function testGetUpstreamBranch(): void
    {
        $this->givenProcesses($this->stubProcess('origin/main'));


        $upstream = $this->gitRepository->getUpstreamBranch();

        $this->assertSame('origin/main', $upstream);
    }

    public function testGetUpstreamBranchReturnsNullIfNoUpstream(): void
    {
        $this->givenProcesses($this->stubProcess(''));


        $upstream = $this->gitRepository->getUpstreamBranch();

        $this->assertNull($upstream);
    }

    public function testGetUpstreamBranchReturnsNullIfNotSuccessful(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $upstream = $this->gitRepository->getUpstreamBranch();

        $this->assertNull($upstream);
    }

    public function testIsHeadPushedReturnsFalseWhenNoUpstream(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $this->assertFalse($this->gitRepository->isHeadPushed());
    }

    public function testIsHeadPushedReturnsFalseWhenHeadRevParseFails(): void
    {
        $this->givenProcesses(
            $this->stubProcess('origin/main'),
            $this->stubProcess('', false),
        );

        $this->assertFalse($this->gitRepository->isHeadPushed());
    }

    public function testIsHeadPushedReturnsFalseWhenUpstreamRevParseFails(): void
    {
        $this->givenProcesses(
            $this->stubProcess('origin/main'),
            $this->stubProcess('abc123'),
            $this->stubProcess('', false),
        );

        $this->assertFalse($this->gitRepository->isHeadPushed());
    }

    public function testIsHeadPushedReturnsTrueWhenHeadEqualsUpstream(): void
    {
        $sameSha = 'abc123def456';

        $this->givenProcesses(
            $this->stubProcess('origin/main'),
            $this->stubProcess($sameSha),
            $this->stubProcess($sameSha),
        );

        $this->assertTrue($this->gitRepository->isHeadPushed());
    }

    public function testIsHeadPushedReturnsFalseWhenHeadDiffersFromUpstream(): void
    {
        $this->givenProcesses(
            $this->stubProcess('origin/main'),
            $this->stubProcess('abc123'),
            $this->stubProcess('def456'),
        );

        $this->assertFalse($this->gitRepository->isHeadPushed());
    }

    public function testHasAtLeastOneCommitReturnsTrueWhenHeadExists(): void
    {
        $this->givenProcesses($this->stubProcess());


        $this->assertTrue($this->gitRepository->hasAtLeastOneCommit());
    }

    public function testHasAtLeastOneCommitReturnsFalseWhenNoCommits(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $this->assertFalse($this->gitRepository->hasAtLeastOneCommit());
    }

    public function testUndoLastCommitThrowsWhenResetFails(): void
    {
        $this->expectException(\App\Exception\GitException::class);

        $this->givenProcesses($this->stubFailingProcess());


        $this->gitRepository->undoLastCommit();
    }

    public function testFindLatestLogicalSha(): void
    {
        $this->givenProcesses($this->stubProcess('abcdef'));


        $sha = $this->gitRepository->findLatestLogicalSha('origin/develop');

        $this->assertSame('abcdef', $sha);
    }

    public function testFindLatestLogicalShaReturnsNullIfNotFound(): void
    {
        $this->givenProcesses($this->stubProcess(''));


        $sha = $this->gitRepository->findLatestLogicalSha('origin/develop');

        $this->assertNull($sha);
    }

    public function testFindLatestLogicalShaReturnsNullIfNotSuccessful(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $sha = $this->gitRepository->findLatestLogicalSha('origin/develop');

        $this->assertNull($sha);
    }

    public function testGetPorcelainStatus(): void
    {
        $this->givenProcesses($this->stubProcess(" M file.txt\n"));


        $status = $this->gitRepository->getPorcelainStatus();

        $this->assertSame(" M file.txt\n", $status);
    }

    public function testHasStagedChangesWhenIndexDirty(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $this->assertTrue($this->gitRepository->hasStagedChanges());
    }

    public function testHasStagedChangesWhenIndexClean(): void
    {
        $this->givenProcesses($this->stubProcess());


        $this->assertFalse($this->gitRepository->hasStagedChanges());
    }

    public function testGetCurrentBranchName(): void
    {
        $this->givenProcesses($this->stubProcess('feature/PROJ-123-my-feature'));


        $branchName = $this->gitRepository->getCurrentBranchName();

        $this->assertSame('feature/PROJ-123-my-feature', $branchName);
    }

    public function testGetMergeBase(): void
    {
        $this->givenProcesses($this->stubProcess('abcdef'));


        $mergeBase = $this->gitRepository->getMergeBase('origin/develop', 'HEAD');

        $this->assertSame('abcdef', $mergeBase);
    }

    public function testFindFirstLogicalSha(): void
    {
        $this->givenProcesses($this->stubProcess('ghijkl'));


        $sha = $this->gitRepository->findFirstLogicalSha('abcdef');

        $this->assertSame('ghijkl', $sha);
    }

    public function testFindFirstLogicalShaSkipsFixupPrefixedLines(): void
    {
        $this->givenProcesses($this->stubProcess(" fixup!\nghijkl\n"));


        $sha = $this->gitRepository->findFirstLogicalSha('abcdef');

        $this->assertSame('ghijkl', $sha);
    }

    public function testFindFirstLogicalShaReturnsNullIfNotFound(): void
    {
        $this->givenProcesses($this->stubProcess(''));


        $sha = $this->gitRepository->findFirstLogicalSha('abcdef');

        $this->assertNull($sha);
    }

    public function testFindFirstLogicalShaReturnsNullIfNotSuccessful(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $sha = $this->gitRepository->findFirstLogicalSha('abcdef');

        $this->assertNull($sha);
    }

    public function testGetCommitMessage(): void
    {
        $this->givenProcesses($this->stubProcess('feat: my commit message'));


        $message = $this->gitRepository->getCommitMessage('abcdef');

        $this->assertSame('feat: my commit message', $message);
    }

    public function testLocalBranchExists(): void
    {
        $this->givenProcesses($this->stubProcess());


        $exists = $this->gitRepository->localBranchExists('my-branch');

        $this->assertTrue($exists);
    }

    public function testLocalBranchDoesNotExist(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $exists = $this->gitRepository->localBranchExists('my-branch');

        $this->assertFalse($exists);
    }

    public function testRemoteBranchExists(): void
    {
        $this->givenProcesses($this->stubProcess('abcdef refs/heads/my-branch'));


        $exists = $this->gitRepository->remoteBranchExists('origin', 'my-branch');

        $this->assertTrue($exists);
    }

    public function testRemoteBranchDoesNotExist(): void
    {
        $this->givenProcesses($this->stubProcess(''));


        $exists = $this->gitRepository->remoteBranchExists('origin', 'my-branch');

        $this->assertFalse($exists);
    }

    public function testRunThrowsGitExceptionOnFailure(): void
    {
        $this->givenProcesses($this->stubFailingProcess('Error: command failed', ''));



        $this->expectException(\App\Exception\GitException::class);
        $this->expectExceptionMessage('Git command failed: my command');

        $this->gitRepository->run(['my', 'command']);
    }

    public function testRunThrowsGitExceptionWithOutputWhenErrorOutputEmpty(): void
    {
        $this->givenProcesses($this->stubFailingProcess('', 'Some output'));




        $this->expectException(\App\Exception\GitException::class);
        $this->expectExceptionMessage('Git command failed: my command');

        try {
            $this->gitRepository->run(['my', 'command']);
        } catch (\App\Exception\GitException $e) {
            $this->assertSame('Some output', $e->getTechnicalDetails());

            throw $e;
        }
    }

    public function testRunThrowsGitExceptionWithDefaultMessageWhenBothOutputsEmpty(): void
    {
        $this->givenProcesses($this->stubFailingProcess());




        $this->expectException(\App\Exception\GitException::class);
        $this->expectExceptionMessage('Git command failed: my command');

        try {
            $this->gitRepository->run(['my', 'command']);
        } catch (\App\Exception\GitException $e) {
            $this->assertSame('Command failed with no error output', $e->getTechnicalDetails());

            throw $e;
        }
    }

    public function testRunThrowsGitExceptionOnProcessTimeout(): void
    {
        $this->givenProcesses($this->stubTimedOutProcess());


        $this->translationService->expects($this->never())
            ->method('trans');

        $this->expectException(\App\Exception\GitTimeoutException::class);

        try {
            $this->gitRepository->run(['git', 'commit', '-m', 'test']);
        } catch (\App\Exception\GitTimeoutException $e) {
            $this->assertSame('git commit -m test', $e->getCommand());
            $this->assertSame(\App\Service\ProcessFactory::GIT_SUBPROCESS_TIMEOUT_SECONDS, $e->getTimeoutSeconds());
            $this->assertNotEmpty($e->getTechnicalDetails());

            throw $e;
        }
    }

    public function testHasFixupCommitsWithFixups(): void
    {
        $this->givenProcesses($this->stubProcess("fixup! Initial commit\nsquash! Another commit"));


        $result = $this->gitRepository->hasFixupCommits('abc123');

        $this->assertTrue($result);
    }

    public function testHasFixupCommitsWithoutFixups(): void
    {
        $this->givenProcesses($this->stubProcess(''));


        $result = $this->gitRepository->hasFixupCommits('abc123');

        $this->assertFalse($result);
    }

    public function testHasFixupCommitsWithFailedProcess(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $result = $this->gitRepository->hasFixupCommits('abc123');

        $this->assertFalse($result);
    }

    public function testRebaseAutosquashCleansUpBackupFile(): void
    {
        // This test verifies that backup files are cleaned up
        // We'll create a scenario where sed would create a backup file
        $scriptPath = null;
        $process = $this->stubProcess();
        $process->method('setEnv')->willReturnCallback(function (array $env) use (&$scriptPath, $process): Process {
            if (isset($env['GIT_SEQUENCE_EDITOR'])) {
                $scriptPath = $env['GIT_SEQUENCE_EDITOR'];
                // Create a backup file using FileSystem to test cleanup (line 155)
                $backupPath = $scriptPath . '.bak';
                $this->fileSystem->write($backupPath, 'backup content');
                // Verify it exists before cleanup
                $this->assertTrue($this->fileSystem->fileExists($backupPath));
            }

            return $process;
        });
        $this->givenProcesses($process);

        $this->gitRepository->rebaseAutosquash('abc123');

        $this->assertNotNull($scriptPath);
        $this->assertFalse($this->fileSystem->fileExists($scriptPath . '.bak'));
    }

    public function testGetRepositoryOwnerWithSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@github.com:studapart/stud-cli.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerWithHttpsUrl(): void
    {
        $this->givenProcesses($this->stubProcess('https://github.com/studapart/stud-cli.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerWithHttpsUrlWithoutGitExtension(): void
    {
        $this->givenProcesses($this->stubProcess('https://github.com/studapart/stud-cli'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerReturnsNullIfNotSuccessful(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertNull($owner);
    }

    public function testGetRepositoryOwnerWithGitLabSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@gitlab.com:studapart/stud-cli.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerWithGitLabHttpsUrl(): void
    {
        $this->givenProcesses($this->stubProcess('https://gitlab.com/studapart/stud-cli.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryNameWithGitLabSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@gitlab.com:studapart/stud-cli.git'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryOwnerReturnsNullIfUrlDoesNotMatchPattern(): void
    {
        $this->givenProcesses($this->stubProcess('file:///path/to/repo.git'));

        // Use a URL format that doesn't match any known pattern (file:// or invalid format)

        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertNull($owner);
    }

    public function testGetRepositoryNameWithSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@github.com:studapart/stud-cli.git'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryNameWithHttpsUrl(): void
    {
        $this->givenProcesses($this->stubProcess('https://github.com/studapart/stud-cli.git'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryNameWithHttpsUrlWithoutGitExtension(): void
    {
        $this->givenProcesses($this->stubProcess('https://github.com/studapart/stud-cli'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryNameReturnsNullIfNotSuccessful(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertNull($name);
    }

    public function testGetRepositoryOwnerWithCustomGitLabInstanceSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@git.example.com:studapart/stud-cli.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryOwnerWithCustomGitLabInstanceHttpsUrl(): void
    {
        $this->givenProcesses($this->stubProcess('https://git.example.com/studapart/stud-cli.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('studapart', $owner);
    }

    public function testGetRepositoryNameWithCustomGitLabInstanceSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@git.example.com:studapart/stud-cli.git'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryNameWithCustomGitLabInstanceHttpsUrl(): void
    {
        $this->givenProcesses($this->stubProcess('https://git.example.com/studapart/stud-cli.git'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('stud-cli', $name);
    }

    public function testGetRepositoryOwnerWithGitLabNestedGroupSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@gitlab.com:group/subgroup/repo.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('group/subgroup', $owner);
    }

    public function testGetRepositoryOwnerWithGitLabNestedGroupHttpsUrl(): void
    {
        $this->givenProcesses($this->stubProcess('https://gitlab.com/group/subgroup/repo.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('group/subgroup', $owner);
    }

    public function testGetRepositoryNameWithGitLabNestedGroupSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@gitlab.com:group/subgroup/repo.git'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('repo', $name);
    }

    public function testGetRepositoryNameWithGitLabNestedGroupHttpsUrl(): void
    {
        $this->givenProcesses($this->stubProcess('https://gitlab.com/group/subgroup/repo.git'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('repo', $name);
    }

    public function testGetRepositoryOwnerWithCustomGitLabInstanceNestedGroupSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@git.example.com:group/subgroup/repo.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('group/subgroup', $owner);
    }

    public function testGetRepositoryOwnerWithCustomGitLabInstanceNestedGroupHttpsUrl(): void
    {
        $this->givenProcesses($this->stubProcess('https://git.example.com/group/subgroup/repo.git'));


        $owner = $this->gitRepository->getRepositoryOwner('origin');

        $this->assertSame('group/subgroup', $owner);
    }

    public function testGetRepositoryNameWithCustomGitLabInstanceNestedGroupSshUrl(): void
    {
        $this->givenProcesses($this->stubProcess('git@git.example.com:group/subgroup/repo.git'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('repo', $name);
    }

    public function testGetRepositoryNameWithCustomGitLabInstanceNestedGroupHttpsUrl(): void
    {
        $this->givenProcesses($this->stubProcess('https://git.example.com/group/subgroup/repo.git'));


        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertSame('repo', $name);
    }

    public function testGetRepositoryNameReturnsNullIfUrlDoesNotMatchPattern(): void
    {
        $this->givenProcesses($this->stubProcess('file:///path/to/repo.git'));

        // Use a URL format that doesn't match any known pattern (file:// or invalid format)

        $name = $this->gitRepository->getRepositoryName('origin');

        $this->assertNull($name);
    }

    public function testGetProjectConfigPath(): void
    {
        $this->givenProcesses($this->stubProcess('.git'));


        $path = $this->gitRepository->getProjectConfigPath();

        $this->assertSame('.git/stud.config', $path);
    }

    public function testGetProjectConfigPathWithTrailingSlash(): void
    {
        $this->givenProcesses($this->stubProcess('.git/'));


        $path = $this->gitRepository->getProjectConfigPath();

        $this->assertSame('.git/stud.config', $path);
    }

    public function testGetProjectConfigPathThrowsExceptionIfNotGitRepo(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not in a git repository.');

        $this->gitRepository->getProjectConfigPath();
    }

    public function testReadProjectConfigReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        // Mock getProjectConfigPath to return a non-existent file path in in-memory filesystem
        $configDir = '/test/git-dir';

        // We need to mock the getProjectConfigPath call
        $this->givenProcesses($this->stubProcess($configDir));


        $config = $this->gitRepository->readProjectConfig();

        $this->assertIsArray($config);
        $this->assertEmpty($config);
    }

    public function testReadProjectConfigReturnsParsedConfig(): void
    {
        $configDir = '/test/git-dir';
        $configData = [
            'projectKey' => 'TEST',
            'transitionId' => 11,
        ];

        // Create config file in in-memory filesystem
        $expectedPath = $configDir . '/stud.config';
        $this->flysystem->write($expectedPath, \Symfony\Component\Yaml\Yaml::dump($configData));

        $this->givenProcesses($this->stubProcess($configDir));


        $config = $this->gitRepository->readProjectConfig();

        $this->assertIsArray($config);
        $this->assertSame('TEST', $config['projectKey']);
        $this->assertSame(11, $config['transitionId']);
    }

    public function testReadProjectConfigResultDelegatesToProjectConfigService(): void
    {
        $configDir = '/test/git-dir';
        $configData = [
            'projectKey' => 'TEST',
            'transitionId' => 11,
        ];

        $expectedPath = $configDir . '/stud.config';
        $this->flysystem->write($expectedPath, \Symfony\Component\Yaml\Yaml::dump($configData));

        $this->givenProcesses($this->stubProcess($configDir));


        $result = $this->gitRepository->readProjectConfigResult();

        $this->assertFalse($result->isUnreadable());
        $this->assertSame('TEST', $result->config['projectKey']);
        $this->assertSame(11, $result->config['transitionId']);
    }

    public function testWriteProjectConfig(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        // Create directory in in-memory filesystem
        $this->flysystem->createDirectory($configDir);

        $this->givenProcesses($this->stubProcess($configDir));


        $config = [
            'projectKey' => 'TEST',
            'transitionId' => 11,
        ];

        $this->gitRepository->writeProjectConfig($config);

        $this->assertTrue($this->fileSystem->fileExists($configPath));
        $parsed = $this->fileSystem->parseFile($configPath);
        $this->assertSame('TEST', $parsed['projectKey']);
        $this->assertSame(11, $parsed['transitionId']);
        $backupPaths = array_filter(
            $this->fileSystem->listDirectory($configDir),
            static fn (string $path): bool => str_contains($path, 'stud.config.bak.')
        );
        $this->assertSame([], array_values($backupPaths));
    }

    public function testWriteProjectConfigPreservesMigrationVersion(): void
    {
        // Test that writeProjectConfig preserves migration_version from existing config
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);

        // Create existing config with migration_version
        $existingConfig = [
            'projectKey' => 'OLD',
            'migration_version' => '202501150000001',
        ];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($existingConfig));

        $this->givenProcesses($this->stubProcess($configDir));

        $newConfig = [
            'projectKey' => 'NEW',
            'transitionId' => 11,
        ];

        $this->gitRepository->writeProjectConfig($newConfig);

        $parsed = $this->fileSystem->parseFile($configPath);
        $this->assertSame('NEW', $parsed['projectKey']);
        $this->assertSame(11, $parsed['transitionId']);
        // migration_version should be preserved
        $this->assertSame('202501150000001', $parsed['migration_version']);
        $backupPaths = array_values(array_filter(
            $this->fileSystem->listDirectory($configDir),
            static fn (string $path): bool => str_contains($path, 'stud.config.bak.')
        ));
        $this->assertCount(1, $backupPaths);
        $backupConfig = $this->fileSystem->parseFile($backupPaths[0]);
        $this->assertSame($existingConfig, $backupConfig);
    }

    public function testWriteProjectConfigDoesNotAddMigrationVersionWhenNotExists(): void
    {
        // Test that writeProjectConfig doesn't add migration_version if it doesn't exist
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);

        // Create existing config without migration_version
        $existingConfig = [
            'projectKey' => 'OLD',
        ];
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump($existingConfig));

        $this->givenProcesses($this->stubProcess($configDir));

        $newConfig = [
            'projectKey' => 'NEW',
            'transitionId' => 11,
        ];

        $this->gitRepository->writeProjectConfig($newConfig);

        $parsed = $this->fileSystem->parseFile($configPath);
        $this->assertSame('NEW', $parsed['projectKey']);
        $this->assertSame(11, $parsed['transitionId']);
        // migration_version should not be present
        $this->assertArrayNotHasKey('migration_version', $parsed);
    }

    public function testWriteProjectConfigThrowsExceptionWhenDirectoryDoesNotExist(): void
    {
        $nonExistentDir = '/test/nonexistent-dir';

        $this->givenProcesses($this->stubProcess($nonExistentDir));


        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Git directory not found: {$nonExistentDir}");

        $config = [
            'projectKey' => 'TEST',
            'transitionId' => 11,
        ];

        $this->gitRepository->writeProjectConfig($config);
    }

    public function testReadProjectConfigReturnsEmptyArrayWhenParseFileThrowsException(): void
    {
        // Test with a file that exists but causes parse error in FileSystem
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        // Create a mock FileSystem that throws an exception when parseFile is called
        $mockFileSystem = $this->createMock(FileSystem::class);
        $mockFileSystem->method('fileExists')->willReturn(true);
        $mockFileSystem->method('parseFile')->willThrowException(new \Exception('Parse error'));

        $gitRepository = GitRepositoryTestKit::create($this->processFactory, $mockFileSystem);

        $this->givenProcesses($this->stubProcess($configDir));


        $config = $gitRepository->readProjectConfig();

        $this->assertIsArray($config);
        $this->assertEmpty($config);
    }

    public function testGetProjectKeyFromIssueKey(): void
    {
        $this->assertSame('TEST', $this->gitRepository->getProjectKeyFromIssueKey('TEST-123'));
        $this->assertSame('PROJ', $this->gitRepository->getProjectKeyFromIssueKey('PROJ-456'));
        $this->assertSame('ABC', $this->gitRepository->getProjectKeyFromIssueKey('abc-789'));
    }

    public function testGetProjectKeyFromIssueKeyThrowsExceptionForInvalidFormat(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid issue key format: INVALID');

        $this->gitRepository->getProjectKeyFromIssueKey('INVALID');
    }

    public function testGetGitProviderReturnsConfiguredProvider(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump(['gitProvider' => 'github']));

        $this->givenProcesses($this->stubProcess($configDir));

        $this->assertSame('github', $this->gitRepository->getGitProvider());
    }

    public function testGetGitProviderReturnsNullWhenNotConfigured(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump([]));

        $this->givenProcesses(
            $this->stubProcess($configDir),
            $this->stubProcess('file:///path/to/repo.git'),
        );

        $this->assertNull($this->gitRepository->getGitProvider());
    }

    public function testGetGitProviderReturnsAutoDetectedProvider(): void
    {
        $configDir = '/test/git-dir';
        $configPath = $configDir . '/stud.config';

        $this->flysystem->createDirectory($configDir);
        $this->flysystem->write($configPath, \Symfony\Component\Yaml\Yaml::dump([]));

        $this->givenProcesses(
            $this->stubProcess($configDir),
            $this->stubProcess('git@github.com:owner/repo.git'),
        );

        $this->assertSame('github', $this->gitRepository->getGitProvider());
    }

    public function testRebaseAutosquashHandlesCleanupErrorForTempScript(): void
    {
        // Test line 148: cleanup error catch block when deleting temp script fails
        $baseSha = 'abc123';

        $this->givenProcesses($this->stubProcess());


        // Mock FileSystem to throw RuntimeException on delete for temp script
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->method('fileExists')->willReturnCallback(function ($path) {
            // Return true for backup file, false for temp script (already deleted)
            return str_ends_with($path, '.bak');
        });
        $fileSystem->method('filePutContents')->willReturnCallback(function ($path, $contents) {
            // Allow writing temp script
        });
        $fileSystem->method('dirname')->willReturnCallback(function ($path) {
            return dirname($path);
        });

        // Make delete() throw RuntimeException for temp script (line 148)
        $fileSystem->expects($this->atLeastOnce())
            ->method('delete')
            ->willReturnCallback(function ($path) {
                if (! str_ends_with($path, '.bak')) {
                    // Throw exception for temp script deletion
                    throw new \RuntimeException('Cleanup error');
                }
            });

        $gitRepository = GitRepositoryTestKit::create($this->processFactory, $fileSystem);

        // Should not throw exception - cleanup errors are caught and ignored
        $gitRepository->rebaseAutosquash($baseSha);
    }

    public function testRebaseAutosquashHandlesCleanupErrorForBackupFile(): void
    {
        // Test line 155: cleanup error catch block when deleting backup file fails
        $baseSha = 'abc123';

        $this->givenProcesses($this->stubProcess());


        // Mock FileSystem to throw RuntimeException on delete for backup file
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->method('fileExists')->willReturn(true); // Backup file exists
        $fileSystem->method('filePutContents')->willReturnCallback(function ($path, $contents) {
            // Allow writing temp script
        });
        $fileSystem->method('dirname')->willReturnCallback(function ($path) {
            return dirname($path);
        });

        // Make delete() throw RuntimeException for backup file (line 155)
        $fileSystem->expects($this->atLeastOnce())
            ->method('delete')
            ->willReturnCallback(function ($path) {
                if (str_ends_with($path, '.bak')) {
                    // Throw exception for backup file deletion
                    throw new \RuntimeException('Cleanup error');
                }
            });

        $gitRepository = GitRepositoryTestKit::create($this->processFactory, $fileSystem);

        // Should not throw exception - cleanup errors are caught and ignored
        $gitRepository->rebaseAutosquash($baseSha);
    }

    public function testTryRebaseReturnsTrue(): void
    {
        $this->givenProcesses($this->stubProcess());


        $this->assertTrue($this->gitRepository->tryRebase('origin/develop'));
    }

    public function testTryRebaseReturnsFalseOnConflict(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $this->assertFalse($this->gitRepository->tryRebase('origin/develop'));
    }

    public function testIsAncestorReturnsTrue(): void
    {
        $this->givenProcesses($this->stubProcess());


        $this->assertTrue($this->gitRepository->isAncestor('origin/develop', 'HEAD'));
    }

    public function testIsAncestorReturnsFalse(): void
    {
        $this->givenProcesses($this->stubProcess('', false));


        $this->assertFalse($this->gitRepository->isAncestor('origin/develop', 'HEAD'));
    }

    private function stubProcess(string $output = '', bool $successful = true): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn($successful);
        $process->method('getOutput')->willReturn($output);
        $process->method('run')->willReturn(0);
        $process->method('mustRun')->willReturn($process);
        $process->method('setEnv')->willReturn($process);

        return $process;
    }

    private function stubFailingProcess(string $errorOutput = '', string $output = ''): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn($errorOutput);
        $process->method('getOutput')->willReturn($output);
        $process->method('getCommandLine')->willReturn('');
        $process->method('getExitCode')->willReturn(1);
        $process->method('getExitCodeText')->willReturn('General error');
        $process->method('getWorkingDirectory')->willReturn('');
        $process->method('isOutputDisabled')->willReturn(false);
        $process->method('mustRun')->willThrowException(new ProcessFailedException($process));

        return $process;
    }

    private function stubTimedOutProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getCommandLine')->willReturn('git commit -m test');
        $process->method('getTimeout')->willReturn(1.0);
        $process->method('mustRun')->willThrowException(new ProcessTimedOutException(
            $process,
            ProcessTimedOutException::TYPE_GENERAL,
        ));

        return $process;
    }

    private function givenProcesses(Process $first, Process ...$rest): void
    {
        if ($rest === []) {
            $this->processFactory->method('create')->willReturn($first);

            return;
        }

        $this->processFactory->method('create')->willReturnOnConsecutiveCalls($first, ...$rest);
    }
}
