<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[Group('integration')]
class GitWorkflowCliParityIntegrationTest extends TestCase
{
    private string $tempDir;

    private string $castorBin;

    private string $castorFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/stud-cli-sci-120-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0700, true);
        $projectRoot = dirname(__DIR__, 2);
        $this->castorBin = $projectRoot . '/vendor/bin/castor';
        $this->castorFile = $projectRoot . '/castor.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function testNativeHelpWorksForCommitAndPush(): void
    {
        foreach ([['commit', '--help'], ['push', '--help'], ['co', '--help'], ['ps', '--help']] as $arguments) {
            $process = $this->runStud($arguments);

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringNotContainsString('TaskCommand::setCommand', $process->getErrorOutput());
            self::assertStringContainsString('Usage:', $process->getOutput());
        }
    }

    public function testAgentHelpOutputIsCleanJson(): void
    {
        $process = $this->runStud(['help', '--agent'], getcwd() ?: null, '{"command":"co"}');

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringStartsWith('{"success":true', $process->getOutput());
        self::assertSame('commit', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['data']['name']);
    }

    public function testCommitShortFlagsStageAllChanges(): void
    {
        $repo = $this->createRepository('commit-short');
        file_put_contents($repo . '/change.txt', 'changed');

        $process = $this->runStud(['co', '-a', '-q', '-m', 'test commit'], $repo);

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('', $this->runProcess(['git', 'status', '--short'], $repo)->getOutput());
        self::assertStringContainsString('test commit', $this->runProcess(['git', 'log', '-1', '--pretty=%B'], $repo)->getOutput());
    }

    public function testPushShortFlagsStageCommitAndPush(): void
    {
        $repo = $this->createRepository('push-short');
        file_put_contents($repo . '/pushed.txt', 'pushed');

        $process = $this->runStud(['push', '-a', '-q', '-m', 'push commit'], $repo);

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('', $this->runProcess(['git', 'status', '--short'], $repo)->getOutput());
        self::assertStringContainsString('push commit', $this->runProcess(['git', 'log', '-1', '--pretty=%B'], $repo)->getOutput());
        self::assertSame(0, $this->runProcess(['git', 'rev-parse', '--verify', 'feature/push-short'], $this->tempDir . '/remote.git')->getExitCode());
    }

    /**
     * Create a local repository with isolated stud config and a bare origin.
     */
    protected function createRepository(string $name): string
    {
        $repo = $this->tempDir . '/' . $name;
        $remote = $this->tempDir . '/remote.git';
        $this->runProcess(['git', 'init', '--bare', $remote], $this->tempDir);
        $this->runProcess(['git', 'init', '-b', 'main', $repo], $this->tempDir);
        $this->configureGitRepository($repo);
        file_put_contents($repo . '/README.md', 'initial');
        $this->runProcess(['git', 'add', 'README.md'], $repo);
        $this->runProcess(['git', 'commit', '-m', 'initial commit'], $repo);
        $this->runProcess(['git', 'remote', 'add', 'origin', $remote], $repo);
        $this->runProcess(['git', 'push', '-u', 'origin', 'main'], $repo);
        $this->runProcess(['git', 'switch', '-c', 'feature/' . $name], $repo);
        file_put_contents($repo . '/.git/stud.config', "baseBranch: main\nmigration_version: '999999999999999'\n");
        $this->writeGlobalConfig();

        return $repo;
    }

    /**
     * Configure git identity for commits created inside the disposable repository.
     */
    protected function configureGitRepository(string $repo): void
    {
        $this->runProcess(['git', 'config', 'user.email', 'test@example.com'], $repo);
        $this->runProcess(['git', 'config', 'user.name', 'Stud CLI Test'], $repo);
    }

    /**
     * Write the minimal global stud config needed to pass command listeners.
     */
    protected function writeGlobalConfig(): void
    {
        $configDir = $this->tempDir . '/home/.config/stud';
        mkdir($configDir, 0700, true);
        file_put_contents(
            $configDir . '/config.yml',
            "LANGUAGE: en\nJIRA_URL: https://example.atlassian.net\nJIRA_EMAIL: test@example.com\nJIRA_API_TOKEN: test-token\nmigration_version: '999999999999999'\n"
        );
    }

    /**
     * Run the working-tree stud wrapper with deterministic environment.
     */
    protected function runStud(array $arguments, ?string $cwd = null, ?string $input = null): Process
    {
        $env = [];
        if ($cwd !== null && is_dir($cwd . '/.git')) {
            $env = [
                'GIT_DIR' => $cwd . '/.git',
                'GIT_WORK_TREE' => $cwd,
            ];
        }

        return $this->runProcess(
            array_merge(['php', $this->castorBin, '--castor-file', $this->castorFile], $arguments),
            dirname(__DIR__, 2),
            $input,
            $env
        );
    }

    /**
     * Run a process and return it after execution.
     */
    protected function runProcess(array $command, ?string $cwd = null, ?string $input = null, array $env = []): Process
    {
        $process = new Process($command, $cwd, [
            'APP_ENV' => 'test',
            'CASTOR_DISABLE_VERSION_CHECK' => '1',
            'HOME' => $this->tempDir . '/home',
        ] + $env, $input, 30);
        $process->run();

        return $process;
    }

    /**
     * Recursively delete the temporary fixture directory.
     */
    protected function removeDirectory(string $directory): void
    {
        $items = array_diff(scandir($directory) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
