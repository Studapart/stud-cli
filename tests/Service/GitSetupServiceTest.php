<?php

namespace App\Tests\Service;

use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use App\Service\Logger;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GitSetupServiceTest extends TestCase
{
    private GitSetupService $gitSetupService;
    private GitRepository&MockObject $gitRepository;
    private GitBranchService&MockObject $gitBranchService;
    private Logger&MockObject $logger;
    private TranslationService&MockObject $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->logger = $this->createMock(Logger::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturnArgument(0);

        $this->gitSetupService = new GitSetupService(
            $this->gitRepository,
            $this->gitBranchService,
            $this->logger,
            $this->translator
        );
    }

    // ── detectBaseBranch ────────────────────────────────────────────────

    public function testDetectBaseBranchReturnsDevelopWhenPresent(): void
    {
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['develop', 'main', 'feature-x']);

        $result = $this->callProtected('detectBaseBranch');

        $this->assertSame('develop', $result);
    }

    public function testDetectBaseBranchReturnsMainWhenDevelopNotPresent(): void
    {
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['main', 'feature-x']);

        $result = $this->callProtected('detectBaseBranch');

        $this->assertSame('main', $result);
    }

    public function testDetectBaseBranchReturnsNullWhenNoCandidatesFound(): void
    {
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['feature-branch']);

        $result = $this->callProtected('detectBaseBranch');

        $this->assertNull($result);
    }

    // ── getBaseBranch ───────────────────────────────────────────────────

    public function testGetBaseBranchReturnsFromConfig(): void
    {
        $this->gitRepository->method('readProjectConfig')
            ->willReturn(['baseBranch' => 'main']);

        $result = $this->callProtected('getBaseBranch');

        $this->assertSame('origin/main', $result);
    }

    public function testGetBaseBranchReturnsFromConfigWithOriginPrefix(): void
    {
        $this->gitRepository->method('readProjectConfig')
            ->willReturn(['baseBranch' => 'origin/main']);

        $result = $this->callProtected('getBaseBranch');

        $this->assertSame('origin/main', $result);
    }

    public function testGetBaseBranchAutoDetectsWhenNotInConfig(): void
    {
        $this->gitRepository->method('readProjectConfig')
            ->willReturn([]);
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['develop', 'main']);

        $result = $this->callProtected('getBaseBranch');

        $this->assertSame('origin/develop', $result);
    }

    public function testGetBaseBranchThrowsExceptionWhenNotConfiguredAndCannotDetect(): void
    {
        $this->gitRepository->method('readProjectConfig')
            ->willReturn([]);
        $this->gitBranchService->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['feature-branch']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Base branch not configured and could not be auto-detected.');

        $this->callProtected('getBaseBranch');
    }

    // ── ensureBaseBranchConfigured ──────────────────────────────────────

    public function testEnsureBaseBranchConfiguredReturnsConfiguredBranchWhenValid(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'main']);
        $this->gitRepository->method('remoteBranchExists')->with('origin', 'main')->willReturn(true);

        $result = $this->gitSetupService->ensureBaseBranchConfigured($io);

        $this->assertSame('origin/main', $result);
    }

    public function testEnsureBaseBranchConfiguredReturnsConfiguredBranchWithOriginPrefix(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'origin/main']);
        $this->gitRepository->method('remoteBranchExists')->with('origin', 'main')->willReturn(true);

        $result = $this->gitSetupService->ensureBaseBranchConfigured($io);

        $this->assertSame('origin/main', $result);
    }

    public function testEnsureBaseBranchConfiguredPromptsWhenConfiguredBranchInvalid(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'nonexistent']);
        $this->gitRepository->method('remoteBranchExists')->willReturnCallback(
            fn (string $remote, string $branch) => $branch === 'develop'
        );
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['develop']);
        $this->logger->method('ask')->willReturn('develop');

        $result = $this->gitSetupService->ensureBaseBranchConfigured($io);

        $this->assertSame('origin/develop', $result);
    }

    public function testEnsureBaseBranchConfiguredPromptsWhenNotConfiguredWithAutoDetection(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn(['main']);
        $this->gitRepository->method('remoteBranchExists')->with('origin', 'main')->willReturn(true);
        $this->logger->method('ask')->willReturn('main');

        $result = $this->gitSetupService->ensureBaseBranchConfigured($io);

        $this->assertSame('origin/main', $result);
    }

    public function testEnsureBaseBranchConfiguredThrowsWhenNotInGitRepo(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')
            ->willThrowException(new \RuntimeException('Not in a git repository.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config.base_branch_required');

        $this->gitSetupService->ensureBaseBranchConfigured($io);
    }

    public function testEnsureBaseBranchConfiguredThrowsWhenUserEntersInvalidBranch(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('remoteBranchExists')->willReturn(false);
        $this->logger->method('ask')->willReturn('invalid-branch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config.base_branch_invalid');

        $this->gitSetupService->ensureBaseBranchConfigured($io);
    }

    public function testEnsureBaseBranchConfiguredThrowsWhenUserEntersEmptyBranch(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->logger->method('ask')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config.base_branch_required');

        $this->gitSetupService->ensureBaseBranchConfigured($io);
    }

    public function testEnsureBaseBranchConfiguredValidatorRejectsEmptyInput(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitBranchService->method('getAllRemoteBranches')->willReturn([]);
        $this->gitRepository->method('remoteBranchExists')->with('origin', 'develop')->willReturn(true);

        $this->logger->method('ask')->willReturnCallback(function ($question, $default, $validator) {
            if ($validator !== null) {
                try {
                    $validator('');
                    $this->fail('Validator should throw for empty string');
                } catch (\RuntimeException $e) {
                    $this->assertSame('Base branch name cannot be empty.', $e->getMessage());
                }

                return $validator('develop');
            }

            return 'develop';
        });

        $result = $this->gitSetupService->ensureBaseBranchConfigured($io);

        $this->assertSame('origin/develop', $result);
    }

    public function testEnsureBaseBranchConfiguredQuietUsesDefaultWhenNotConfigured(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitRepository->method('remoteBranchExists')->with('origin', 'develop')->willReturn(true);

        $this->logger->expects($this->never())->method('ask');

        if (! defined('DEFAULT_BASE_BRANCH')) {
            define('DEFAULT_BASE_BRANCH', 'origin/develop');
        }

        $result = $this->gitSetupService->ensureBaseBranchConfigured($io, true);

        $this->assertSame('origin/develop', $result);
    }

    public function testEnsureBaseBranchConfiguredQuietThrowsWhenDefaultNotOnRemote(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitRepository->method('remoteBranchExists')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist on remote');

        $this->gitSetupService->ensureBaseBranchConfigured($io, true);
    }

    public function testEnsureBaseBranchConfiguredQuietThrowsWhenConfiguredBranchInvalid(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'nonexistent']);
        $this->gitRepository->method('remoteBranchExists')->with('origin', 'nonexistent')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config.base_branch_invalid');

        $this->gitSetupService->ensureBaseBranchConfigured($io, true);
    }

    // ── ensureGitProviderConfigured ─────────────────────────────────────

    public function testEnsureGitProviderConfiguredReturnsConfiguredProvider(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn(['gitProvider' => 'gitlab']);

        $result = $this->gitSetupService->ensureGitProviderConfigured($io);

        $this->assertSame('gitlab', $result);
    }

    public function testEnsureGitProviderConfiguredThrowsWhenNotInGitRepo(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')
            ->willThrowException(new \RuntimeException('Not in a git repository.'));
        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturn('Git provider is required');

        $gitSetupService = new GitSetupService(
            $this->gitRepository,
            $this->gitBranchService,
            $this->logger,
            $this->translator
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git provider is required');

        $gitSetupService->ensureGitProviderConfigured($io);
    }

    public function testEnsureGitProviderConfiguredQuietReturnsDetected(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitRepository->method('parseGitUrl')
            ->with('origin')
            ->willReturn(['owner' => 'owner', 'name' => 'repo', 'provider' => 'github']);

        $this->logger->expects($this->never())->method('choice');

        $result = $this->gitSetupService->ensureGitProviderConfigured($io, true);

        $this->assertSame('github', $result);
    }

    public function testEnsureGitProviderConfiguredQuietThrowsWhenNotDetected(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitRepository->method('parseGitUrl')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git provider is not configured');

        $this->gitSetupService->ensureGitProviderConfigured($io, true);
    }

    public function testEnsureGitProviderConfiguredPromptsWhenNotConfiguredWithAutoDetection(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitRepository->method('parseGitUrl')
            ->willReturn(['owner' => 'owner', 'name' => 'repo', 'provider' => 'gitlab']);
        $this->logger->method('choice')->willReturn('gitlab');

        $result = $this->gitSetupService->ensureGitProviderConfigured($io);

        $this->assertSame('gitlab', $result);
    }

    public function testEnsureGitProviderConfiguredPromptsWhenNotConfiguredWithoutAutoDetection(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitRepository->method('parseGitUrl')->willReturn([]);
        $this->logger->method('choice')->willReturn('github');

        $result = $this->gitSetupService->ensureGitProviderConfigured($io);

        $this->assertSame('github', $result);
    }

    public function testEnsureGitProviderConfiguredThrowsWhenInvalidChoice(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitRepository->method('parseGitUrl')->willReturn([]);
        $this->logger->method('choice')->willReturn(null);

        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturn('Git provider is required');

        $gitSetupService = new GitSetupService(
            $this->gitRepository,
            $this->gitBranchService,
            $this->logger,
            $this->translator
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git provider is required');

        $gitSetupService->ensureGitProviderConfigured($io);
    }

    // ── ensureGitTokenConfigured ────────────────────────────────────────

    public function testEnsureGitTokenConfiguredReturnsTokenFromProjectConfig(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn(['githubToken' => 'test_token']);

        $result = $this->gitSetupService->ensureGitTokenConfigured('github', $io, []);

        $this->assertSame('test_token', $result);
    }

    public function testEnsureGitTokenConfiguredReturnsTokenFromGlobalConfig(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);

        $result = $this->gitSetupService->ensureGitTokenConfigured(
            'github',
            $io,
            ['GITHUB_TOKEN' => 'global_token']
        );

        $this->assertSame('global_token', $result);
    }

    public function testEnsureGitTokenConfiguredReturnsTokenFromGlobalConfigForGitLab(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);

        $result = $this->gitSetupService->ensureGitTokenConfigured(
            'gitlab',
            $io,
            ['GITLAB_TOKEN' => 'gitlab_token']
        );

        $this->assertSame('gitlab_token', $result);
    }

    public function testEnsureGitTokenConfiguredThrowsWhenNotInGitRepo(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')
            ->willThrowException(new \RuntimeException('Not in a git repository.'));

        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturn('Git token is required');

        $gitSetupService = new GitSetupService(
            $this->gitRepository,
            $this->gitBranchService,
            $this->logger,
            $this->translator
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git token is required');

        $gitSetupService->ensureGitTokenConfigured('github', $io, []);
    }

    public function testEnsureGitTokenConfiguredQuietReturnsNullWhenMissing(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);

        $this->logger->expects($this->never())->method('askHidden');

        $result = $this->gitSetupService->ensureGitTokenConfigured('github', $io, [], true);

        $this->assertNull($result);
    }

    public function testEnsureGitTokenConfiguredWarnsOnTokenTypeMismatch(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);

        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturnCallback(function ($key, $params = []) {
            if ($key === 'config.git_token_type_mismatch') {
                return "Provider is set to '{$params['provider']}' but only {$params['opposite']} token is configured.";
            }

            return $key;
        });

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                Logger::VERBOSITY_NORMAL,
                $this->stringContains('Provider is set to')
            );
        $this->logger->expects($this->once())
            ->method('askHidden')
            ->willReturn(null);

        $gitSetupService = new GitSetupService(
            $this->gitRepository,
            $this->gitBranchService,
            $this->logger,
            $this->translator
        );

        $result = $gitSetupService->ensureGitTokenConfigured(
            'github',
            $io,
            ['GITLAB_TOKEN' => 'gitlab_token']
        );

        $this->assertNull($result);
    }

    public function testEnsureGitTokenConfiguredShowsGlobalSuggestionWhenNoTokens(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);

        $this->logger->expects($this->exactly(2))
            ->method('note')
            ->with(Logger::VERBOSITY_NORMAL, $this->anything());
        $this->logger->expects($this->once())
            ->method('askHidden')
            ->willReturn(null);

        $result = $this->gitSetupService->ensureGitTokenConfigured('github', $io, []);

        $this->assertNull($result);
    }

    public function testEnsureGitTokenConfiguredPromptsAndSavesToken(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with($this->callback(fn (array $config) => ($config['githubToken'] ?? null) === 'new_token'));

        $this->logger->method('askHidden')->willReturn('new_token');

        $result = $this->gitSetupService->ensureGitTokenConfigured('github', $io, []);

        $this->assertSame('new_token', $result);
    }

    public function testEnsureGitTokenConfiguredReturnsNullWhenUserSkips(): void
    {
        $io = $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class);

        $this->gitRepository->method('getProjectConfigPath')->willReturn('/test/.git/stud.config');
        $this->gitRepository->method('readProjectConfig')->willReturn([]);
        $this->logger->method('askHidden')->willReturn('');

        $result = $this->gitSetupService->ensureGitTokenConfigured('github', $io, []);

        $this->assertNull($result);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function callProtected(string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($this->gitSetupService);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($this->gitSetupService, $args);
    }
}
