<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\ConfigProjectInitHandler;
use App\Handler\ConfigProjectInitPromptCollector;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use PHPUnit\Framework\TestCase;

class ConfigProjectInitHandlerTest extends TestCase
{
    public function testNotGitRepositoryReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willThrowException(new \RuntimeException('not a git repo'));
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], [], false, false, false);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.not_git_repository', $response->getError());
    }

    public function testAgentUnknownKeysReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['badKey' => 'x'], [], false, false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.unknown_keys', $response->getError());
        $this->assertSame(['%keys%' => 'badKey'], $response->getErrorParameters());
    }

    public function testAgentReservedMigrationVersionReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['migration_version' => '9'], [], false, false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.reserved_keys', $response->getError());
    }

    public function testAgentInvalidTransitionIdReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['transitionId' => 1.5], [], false, false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.invalid_transition_id', $response->getError());
    }

    public function testNoPatchesReturnsSuccessUnchanged(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'develop']);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], [], false, false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
        $this->assertSame(['baseBranch' => 'develop'], $response->redactedProjectConfig);
        $gitRepository->expects($this->never())->method('writeProjectConfig');
    }

    public function testMergeWritesAndValidatesBaseBranch(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            ['githubToken' => 'keep'],
            ['githubToken' => 'keep', 'projectKey' => 'SCI', 'baseBranch' => 'main']
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with($this->callback(function (array $c): bool {
                return $c['projectKey'] === 'SCI'
                    && $c['baseBranch'] === 'main'
                    && $c['githubToken'] === 'keep';
            }));

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->expects($this->once())->method('validateBaseBranchOnRemote')->with('main');

        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(
            ['projectKey' => 'sci', 'baseBranch' => 'origin/main'],
            [],
            false,
            false,
            true
        );

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->updated);
    }

    public function testSkipBaseBranchRemoteCheckSkipsValidation(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['baseBranch' => 'feature-x']
        );
        $gitRepository->expects($this->once())->method('writeProjectConfig');

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->expects($this->never())->method('validateBaseBranchOnRemote');

        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['baseBranch' => 'feature-x'], [], true, false, true);

        $this->assertTrue($response->isSuccess());
    }

    public function testNegativeTransitionIdFromCliReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], ['transitionId' => -1], false, false, false);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.invalid_transition_id', $response->getError());
    }

    public function testInvalidGitProviderReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['gitProvider' => 'bitbucket'], [], false, false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.invalid_git_provider', $response->getError());
    }

    public function testCliPatchesMergeJiraDefaultProject(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['JIRA_DEFAULT_PROJECT' => 'ABC']
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['JIRA_DEFAULT_PROJECT' => 'XYZ']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], ['jiraDefaultProject' => 'xyz'], false, false, false);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->updated);
    }

    public function testInteractiveModeUsesPromptCollector(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['projectKey' => 'ABC']
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'ABC']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);
        $prompts->expects($this->once())->method('collect')->willReturn(['projectKey' => 'abc']);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], [], true, true, false);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->updated);
    }

    public function testBaseBranchRemoteValidationFailureReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('validateBaseBranchOnRemote')
            ->willThrowException(new \RuntimeException('no such ref'));

        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['baseBranch' => 'gone'], [], false, false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.base_branch_error', $response->getError());
        $this->assertSame(['%message%' => 'no such ref'], $response->getErrorParameters());
    }

    public function testAgentCoercesGitProviderString(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['gitProvider' => 'gitlab']
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with($this->callback(fn (array $c): bool => ($c['gitProvider'] ?? null) === 'gitlab'));

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['gitProvider' => '  GITLAB  '], [], true, false, true);

        $this->assertTrue($response->isSuccess());
    }

    public function testAgentSkipsNullPatchValues(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'develop']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['projectKey' => null], [], false, false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
    }

    public function testAgentValidTransitionIdAsStringDigits(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['transitionId' => 7]
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with($this->callback(fn (array $c): bool => ($c['transitionId'] ?? null) === 7));

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['transitionId' => '  7  '], [], true, false, true);

        $this->assertTrue($response->isSuccess());
    }

    public function testCliNormalizesProjectKeyWhitespace(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['projectKey' => 'ZZZ']
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'ZZZ']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], ['projectKey' => '  zzz  '], true, false, false);

        $this->assertTrue($response->isSuccess());
    }

    public function testAgentTransitionIdNullPassesValidation(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'develop']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['transitionId' => null], [], false, false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
    }

    public function testAgentTransitionIdAsNonNegativeIntegerPassesValidation(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['transitionId' => 42]
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['transitionId' => 42]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['transitionId' => 42], [], true, false, true);

        $this->assertTrue($response->isSuccess());
    }

    public function testCliPatchesSkipKeysNotInFieldMap(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'develop']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], ['notAProjectInitField' => 'x'], false, false, false);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
    }

    public function testAgentCoerceLeavesNonStringValuesUnchanged(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['githubToken' => 99]
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['githubToken' => 99]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['githubToken' => 99], [], true, false, true);

        $this->assertTrue($response->isSuccess());
    }

    public function testAgentSkipsEmptyOrWhitespaceStringPatches(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['githubToken' => 'keep-me']);
        $gitRepository->expects($this->never())->method('writeProjectConfig');

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['githubToken' => '   '], [], true, false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
    }

    public function testCliWhitespaceOnlyProjectKeyDoesNotOverwrite(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['projectKey' => 'KEEP']);
        $gitRepository->expects($this->never())->method('writeProjectConfig');

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], ['projectKey' => '   '], true, false, false);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
    }
}
