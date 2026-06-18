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
        $response = $handler->handle([], false, false);

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
        $response = $handler->handle(['badKey' => 'x'], false, true);

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
        $response = $handler->handle(['migration_version' => '9'], false, true);

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
        $response = $handler->handle(['transitionId' => 1.5], false, true);

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
        $response = $handler->handle([], false, true);

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
        $response = $handler->handle(['projectKey' => 'sci', 'baseBranch' => 'origin/main'], false, true);

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
        $response = $handler->handle(['baseBranch' => 'feature-x'], true, true);

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
        $response = $handler->handle(['transitionId' => -1], false, true);

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
        $response = $handler->handle(['gitProvider' => 'bitbucket'], false, true);

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
        $response = $handler->handle(['jiraDefaultProject' => 'xyz'], false, true);

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
        $response = $handler->handle([], true, false);

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
        $response = $handler->handle(['baseBranch' => 'gone'], false, true);

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
        $response = $handler->handle(['gitProvider' => '  GITLAB  '], true, true);

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
        $response = $handler->handle(['projectKey' => null], false, true);

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
        $response = $handler->handle(['transitionId' => '  7  '], true, true);

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
        $response = $handler->handle(['projectKey' => '  zzz  '], true, true);

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
        $response = $handler->handle(['transitionId' => null], false, true);

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
        $response = $handler->handle(['transitionId' => 42], true, true);

        $this->assertTrue($response->isSuccess());
    }

    public function testAgentUnknownFieldKeyReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'develop']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['notAProjectInitField' => 'x'], false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.unknown_keys', $response->getError());
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
        $response = $handler->handle(['githubToken' => 99], true, true);

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
        $response = $handler->handle(['githubToken' => '   '], true, true);

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
        $response = $handler->handle(['projectKey' => '   '], true, true);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
    }

    public function testAgentWritesLinearWorkItemProviderAndFields(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            [
                'workItemProvider' => 'linear',
                'projectKey' => 'SCI',
                'linearStartStateId' => 'state-uuid',
                'linearTypeLabelGroupId' => 'group-uuid',
                'linearTypeBranchPrefixes' => ['Story' => 'feat'],
            ],
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with([
                'workItemProvider' => 'linear',
                'projectKey' => 'SCI',
                'linearStartStateId' => 'state-uuid',
                'linearTypeLabelGroupId' => 'group-uuid',
                'linearTypeBranchPrefixes' => ['Story' => 'feat'],
            ]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([
            'workItemProvider' => 'linear',
            'projectKey' => 'SCI',
            'linearStartStateId' => 'state-uuid',
            'linearTypeLabelGroupId' => 'group-uuid',
            'linearTypeBranchPrefixes' => ['Story' => 'feat'],
        ], false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->updated);
    }

    public function testAgentInvalidWorkItemProviderReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['workItemProvider' => 'asana'], false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.invalid_work_item_provider', $response->getError());
    }

    public function testAgentInvalidLinearTypeBranchPrefixesReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['linearTypeBranchPrefixes' => 'not-a-map'], false, true);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.invalid_linear_type_branch_prefixes', $response->getError());
    }

    public function testAgentSkipsLinearTypeBranchPrefixesWithEmptyTypeLabel(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'develop']);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['linearTypeBranchPrefixes' => ['' => 'feat']], false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
    }

    public function testAgentSkipsEmptyLinearTypeBranchPrefixesMap(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['baseBranch' => 'develop']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle(['linearTypeBranchPrefixes' => ['' => 'feat', 'Story' => '']], false, true);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
    }

    public function testAgentNormalizesLinearTypeBranchPrefixes(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['linearTypeBranchPrefixes' => ['Story' => 'feat']],
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['linearTypeBranchPrefixes' => ['Story' => 'feat']]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([
            'linearTypeBranchPrefixes' => [' Story ' => ' feat ', 99 => 'fix'],
        ], true, true);

        $this->assertTrue($response->isSuccess());
    }

    public function testInteractiveInvalidLinearTypeBranchPrefixesEmptyKeyReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);
        $prompts->method('collect')->willReturn(['linearTypeBranchPrefixes' => ['' => 'feat']]);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], true, false);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.invalid_linear_type_branch_prefixes', $response->getError());
    }

    public function testInteractiveInvalidLinearTypeBranchPrefixesEmptyValueReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);
        $prompts->method('collect')->willReturn(['linearTypeBranchPrefixes' => ['Story' => '']]);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], true, false);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.invalid_linear_type_branch_prefixes', $response->getError());
    }

    public function testInteractiveIgnoresUnknownPatchKeysFromCollector(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturnOnConsecutiveCalls(
            [],
            ['projectKey' => 'SCI'],
        );
        $gitRepository->expects($this->once())
            ->method('writeProjectConfig')
            ->with(['projectKey' => 'SCI']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);
        $prompts->method('collect')->willReturn(['projectKey' => 'SCI', 'notInFieldMap' => 'x']);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], false, false);

        $this->assertTrue($response->isSuccess());
    }

    public function testInteractiveWhitespaceOnlyStringPatchIsDropped(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn(['projectKey' => 'KEEP']);
        $gitRepository->expects($this->never())->method('writeProjectConfig');

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);
        $prompts->method('collect')->willReturn(['projectKey' => '   ']);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], false, false);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->updated);
    }

    public function testInteractiveInvalidTransitionIdReturnsError(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getProjectConfigPath')->willReturn('/tmp/.git/stud.config');
        $gitRepository->method('readProjectConfig')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $prompts = $this->createMock(ConfigProjectInitPromptCollector::class);
        $prompts->method('collect')->willReturn(['transitionId' => -1]);

        $handler = new ConfigProjectInitHandler($gitRepository, $gitSetup, $prompts);
        $response = $handler->handle([], false, false);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.project_init.invalid_transition_id', $response->getError());
    }
}
