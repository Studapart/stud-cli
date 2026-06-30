<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Linear provider agent-mode integration tests with mocked GraphQL (SCI-168).
 */
#[Group('integration')]
final class LinearAgentModeIntegrationTest extends TestCase
{
    private string $tempDir;

    private string $projectRoot;

    private string $castorBin;

    private string $castorFile;

    private ?Process $mockServer = null;

    private ?string $mockBaseUri = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 2);
        $this->tempDir = sys_get_temp_dir() . '/stud-linear-sci-168-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0700, true);
        $this->castorBin = $this->projectRoot . '/vendor/bin/castor';
        $this->castorFile = $this->projectRoot . '/castor.php';
        $this->startGraphqlMockServer();
        $this->writeGlobalConfig();
    }

    protected function tearDown(): void
    {
        if ($this->mockServer instanceof Process) {
            $this->mockServer->stop(0);
        }

        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function testItemsShowAgentModeReturnsIssueJson(): void
    {
        $repo = $this->createRepository('show');
        $result = $this->runAgentProcess(
            ['items:show', '--agent'],
            ['key' => 'SCI-123', 'provider' => 'linear'],
            $repo,
        );

        self::assertSame(0, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertTrue($decoded['success'] ?? false);
        self::assertSame('SCI-123', $decoded['data']['issue']['key'] ?? null);
        self::assertSame('Linear integration show', $decoded['data']['issue']['title'] ?? null);
        self::assertSame('In Progress', $decoded['data']['issue']['status'] ?? null);
        self::assertSame('Ada Lovelace', $decoded['data']['issue']['assignee'] ?? null);
    }

    public function testItemsListAgentModeReturnsSlimIssues(): void
    {
        $repo = $this->createRepository('list');
        $result = $this->runAgentProcess(
            ['items:list', '--agent'],
            ['all' => false, 'project' => 'ENG', 'provider' => 'linear'],
            $repo,
        );

        self::assertSame(0, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertTrue($decoded['success'] ?? false);
        self::assertFalse($decoded['data']['all'] ?? true);
        self::assertSame('ENG', $decoded['data']['project'] ?? null);
        self::assertCount(2, $decoded['data']['issues'] ?? []);
        self::assertSame('ENG-1', $decoded['data']['issues'][0]['key'] ?? null);
        self::assertSame('Todo', $decoded['data']['issues'][0]['status'] ?? null);
        self::assertSame('First active issue', $decoded['data']['issues'][0]['title'] ?? null);
        self::assertArrayHasKey('url', $decoded['data']['issues'][0]);
    }

    public function testProjectsListAgentModeReturnsTeams(): void
    {
        $repo = $this->createRepository('projects');
        $result = $this->runAgentProcess(
            ['projects:list', '--agent'],
            [],
            $repo,
        );

        self::assertSame(0, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertTrue($decoded['success'] ?? false);
        self::assertCount(2, $decoded['data']['projects'] ?? []);
        self::assertSame('ENG', $decoded['data']['projects'][0]['key'] ?? null);
        self::assertSame('Engineering', $decoded['data']['projects'][0]['name'] ?? null);
    }

    public function testConfigValidateAgentModeLinearPingOk(): void
    {
        $repo = $this->createRepository('validate');
        $result = $this->runAgentProcess(
            ['config:validate', '--agent'],
            ['skipJira' => true, 'skipGit' => true],
            $repo,
        );

        self::assertSame(0, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertTrue($decoded['success'] ?? false);
        self::assertSame('skipped', $decoded['data']['jiraStatus'] ?? null);
        self::assertSame('skipped', $decoded['data']['gitStatus'] ?? null);
        self::assertSame('ok', $decoded['data']['linearStatus'] ?? null);
    }

    public function testItemsTransitionAgentModeAppliesFirstWorkflowState(): void
    {
        $repo = $this->createRepository('transition');
        $result = $this->runAgentProcess(
            ['items:transition', '--agent'],
            ['key' => 'SCI-123', 'provider' => 'linear'],
            $repo,
        );

        self::assertSame(0, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertTrue($decoded['success'] ?? false);
    }

    public function testFiltersListAgentModeReturnsCustomViews(): void
    {
        $repo = $this->createRepository('filters-list');
        $result = $this->runAgentProcess(
            ['filters:list', '--agent'],
            [],
            $repo,
        );

        self::assertSame(0, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertTrue($decoded['success'] ?? false);
        self::assertCount(2, $decoded['data']['filters'] ?? []);
        self::assertSame('Active Bugs', $decoded['data']['filters'][0]['name'] ?? null);
        self::assertSame('Open bugs in the team', $decoded['data']['filters'][0]['description'] ?? null);
        self::assertSame('Empty View', $decoded['data']['filters'][1]['name'] ?? null);
        self::assertArrayNotHasKey('filterData', $decoded['data']['filters'][0]);
    }

    public function testFiltersShowAgentModeExecutesCustomViewFilterData(): void
    {
        $repo = $this->createRepository('filters-show');
        $result = $this->runAgentProcess(
            ['filters:show', '--agent'],
            ['filterName' => 'Active Bugs'],
            $repo,
        );

        self::assertSame(0, $result['exitCode'], 'stderr: ' . $result['stderr']);
        $decoded = $this->assertSingleJsonObject($result['stdout']);
        self::assertTrue($decoded['success'] ?? false);
        self::assertSame('Active Bugs', $decoded['data']['filterName'] ?? null);
        self::assertCount(2, $decoded['data']['issues'] ?? []);
        self::assertSame('ENG-1', $decoded['data']['issues'][0]['key'] ?? null);
        self::assertSame('Todo', $decoded['data']['issues'][0]['status'] ?? null);
        self::assertSame('First active issue', $decoded['data']['issues'][0]['title'] ?? null);
        self::assertArrayHasKey('url', $decoded['data']['issues'][0]);
        self::assertArrayNotHasKey('filterData', $decoded['data']);
    }

    protected function createRepository(string $name): string
    {
        $repo = $this->tempDir . '/' . $name;
        $this->runProcess(['git', 'init', '-b', 'main', $repo], $this->tempDir);
        $this->runProcess(['git', 'config', 'user.email', 'test@example.com'], $repo);
        $this->runProcess(['git', 'config', 'user.name', 'Stud CLI Test'], $repo);
        file_put_contents($repo . '/README.md', 'initial');
        $this->runProcess(['git', 'add', 'README.md'], $repo);
        $this->runProcess(['git', 'commit', '-m', 'initial'], $repo);
        file_put_contents(
            $repo . '/.git/stud.config',
            "workItemProvider: linear\nmigration_version: '999999999999999'\n",
        );

        return $repo;
    }

    protected function writeGlobalConfig(): void
    {
        $configDir = $this->tempDir . '/home/.config/stud';
        mkdir($configDir, 0700, true);
        file_put_contents(
            $configDir . '/config.yml',
            "LANGUAGE: en\nWORK_ITEM_PROVIDERS:\n  - linear\nLINEAR_API_KEY: test-linear-key\nmigration_version: '999999999999999'\n",
        );
    }

    protected function startGraphqlMockServer(): void
    {
        $port = $this->findAvailablePort();
        $this->mockServer = new Process([
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $port,
            $this->projectRoot . '/tests/Fixtures/Linear/graphql-mock-server.php',
        ], $this->projectRoot);
        $this->mockServer->start();
        $this->mockServer->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'Development Server'));
        $this->mockBaseUri = 'http://127.0.0.1:' . $port . '/';
    }

    protected function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            self::fail('Could not create socket for mock GraphQL server.');
        }

        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $address, $port);
        socket_close($socket);

        return $port;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, mixed> $input
     * @return array{exitCode: int|null, stdout: string, stderr: string}
     */
    protected function runAgentProcess(array $arguments, array $input, string $repo): array
    {
        $process = $this->runProcess(
            array_merge(['php', $this->castorBin, '--castor-file', $this->castorFile], $arguments),
            $this->projectRoot,
            (string) json_encode($input, JSON_THROW_ON_ERROR),
            [
                'GIT_DIR' => $repo . '/.git',
                'GIT_WORK_TREE' => $repo,
            ],
        );

        return [
            'exitCode' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * @param list<string> $command
     */
    protected function runProcess(array $command, ?string $cwd = null, ?string $input = null, array $extraEnv = []): Process
    {
        $env = [
            'APP_ENV' => 'test',
            'CASTOR_DISABLE_VERSION_CHECK' => '1',
            'HOME' => $this->tempDir . '/home',
            'STUD_LINEAR_GRAPHQL_BASE_URI' => $this->mockBaseUri ?? '',
        ] + $extraEnv;

        $process = new Process($command, $cwd, $env, $input, 30);
        $process->run();

        return $process;
    }

    /**
     * @return array<string, mixed>
     */
    protected function assertSingleJsonObject(string $stdout): array
    {
        self::assertStringStartsWith('{', $stdout, 'stdout must be JSON from the first byte');
        self::assertMatchesRegularExpression('/}\n?$/', $stdout, 'stdout must end after one JSON object');

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, 'stdout must decode as JSON');

        $normalizedStdout = str_ends_with($stdout, "\n") ? substr($stdout, 0, -1) : $stdout;
        self::assertSame(
            json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $normalizedStdout,
            'stdout must contain exactly one JSON object',
        );

        return $decoded;
    }

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
