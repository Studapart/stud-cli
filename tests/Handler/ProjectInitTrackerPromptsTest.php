<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Handler\ProjectInitGlobalConfig;
use App\Handler\ProjectInitPromptContext;
use App\Handler\ProjectInitTrackerPrompts;
use App\Handler\ProjectInitTrackers;
use App\Service\FileSystem;
use App\Service\GlobalConfigProviderResolver;
use App\Service\ProjectMetadataPromptService;
use App\Service\Prompt\PromptInterface;
use PHPUnit\Framework\TestCase;

class ProjectInitTrackerPromptsTest extends TestCase
{
    private function createPrompts(
        PromptInterface $prompt,
        ?ProjectMetadataPromptService $metadata = null,
    ): ProjectInitTrackerPrompts {
        return new ProjectInitTrackerPrompts(
            $prompt,
            new ProjectInitPromptContext(
                new ProjectInitGlobalConfig(
                    $this->createStub(FileSystem::class),
                    '/tmp/global-config.yml',
                    new GlobalConfigProviderResolver(),
                ),
                $metadata ?? $this->createStub(ProjectMetadataPromptService::class),
            ),
        );
    }

    /**
     * @param array<string, string|null> $answers
     */
    private function promptAnswering(array $answers): PromptInterface
    {
        $prompt = $this->createStub(PromptInterface::class);
        $prompt->method('ask')->willReturnCallback(
            static fn (MessageRef $question): ?string => $answers[$question->key] ?? null,
        );

        return $prompt;
    }

    public function testReturnsEmptyWhenNoTrackerIsSelected(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('ask');

        $patches = $this->createPrompts($prompt)->patches(
            new ProjectInitTrackers(jira: false, linear: false, linearTeamKey: false),
            [],
            ['projectKey' => 'SCI'],
            new WorkflowRecorder(),
        );

        $this->assertSame([], $patches);
    }

    public function testJiraPromptsCollectDefaultsAndTransition(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('ask')
            ->willReturnCallback(function (MessageRef $question, ?string $default): string {
                if ($question->key === 'config.project_init.prompt_jira_default_project') {
                    $this->assertSame('OLD', $default);

                    return ' web ';
                }
                $this->assertSame('config.project_init.prompt_confluence_default_space', $question->key);
                $this->assertSame('DOCS', $default);

                return ' Space ';
            });
        $metadata = $this->createMock(ProjectMetadataPromptService::class);
        $metadata->expects($this->once())
            ->method('chooseJiraTransitionId')
            ->with($this->anything(), 'SCI', ['projectKey' => ' sci '])
            ->willReturn(31);

        $patches = $this->createPrompts($prompt, $metadata)->patches(
            new ProjectInitTrackers(jira: true, linear: false, linearTeamKey: false),
            ['JIRA_DEFAULT_PROJECT' => 'OLD', 'CONFLUENCE_DEFAULT_SPACE' => 'DOCS'],
            ['projectKey' => ' sci '],
            new WorkflowRecorder(),
        );

        $this->assertSame([
            'jiraDefaultProject' => 'WEB',
            'confluenceDefaultSpace' => 'Space',
            'transitionId' => 31,
        ], $patches);
    }

    public function testJiraPromptsSkipBlankAnswersAndMissingTransition(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('ask')
            ->with($this->anything(), null)
            ->willReturnOnConsecutiveCalls('  ', null);
        $metadata = $this->createMock(ProjectMetadataPromptService::class);
        $metadata->expects($this->once())->method('chooseJiraTransitionId')->willReturn(null);

        $patches = $this->createPrompts($prompt, $metadata)->patches(
            new ProjectInitTrackers(jira: true, linear: false, linearTeamKey: false),
            [],
            ['projectKey' => 'SCI'],
            new WorkflowRecorder(),
        );

        $this->assertSame([], $patches);
    }

    public function testJiraTransitionIsSkippedWithoutProjectKey(): void
    {
        $metadata = $this->createMock(ProjectMetadataPromptService::class);
        $metadata->expects($this->never())->method('chooseJiraTransitionId');

        $patches = $this->createPrompts($this->promptAnswering([]), $metadata)->patches(
            new ProjectInitTrackers(jira: true, linear: false, linearTeamKey: false),
            [],
            ['projectKey' => '  '],
            new WorkflowRecorder(),
        );

        $this->assertSame([], $patches);
    }

    public function testLinearTeamKeyAnswerFeedsLinearMetadataScope(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with($this->anything(), 'SCI')
            ->willReturn(' eng ');
        $expectedConfig = ['projectKey' => 'SCI', 'linearTeamKey' => 'ENG'];
        $metadata = $this->createMock(ProjectMetadataPromptService::class);
        $metadata->expects($this->once())
            ->method('chooseLinearStartStateId')
            ->with($this->anything(), 'ENG', $expectedConfig)
            ->willReturn('state-1');
        $metadata->expects($this->once())
            ->method('chooseLinearTypeLabelGroupId')
            ->with($this->anything(), 'ENG', $expectedConfig)
            ->willReturn('group-1');
        $metadata->expects($this->once())
            ->method('buildLinearBranchPrefixMap')
            ->with($this->anything(), 'ENG', $expectedConfig, 'group-1')
            ->willReturn(['Bug' => 'fix']);

        $patches = $this->createPrompts($prompt, $metadata)->patches(
            new ProjectInitTrackers(jira: false, linear: true, linearTeamKey: true),
            [],
            ['projectKey' => 'SCI'],
            new WorkflowRecorder(),
        );

        $this->assertSame([
            'linearTeamKey' => 'ENG',
            'linearStartStateId' => 'state-1',
            'linearTypeLabelGroupId' => 'group-1',
            'linearTypeBranchPrefixes' => ['Bug' => 'fix'],
        ], $patches);
    }

    public function testLinearFieldsSkipMissingMetadataChoices(): void
    {
        $metadata = $this->createStub(ProjectMetadataPromptService::class);
        $metadata->method('chooseLinearStartStateId')->willReturn(null);
        $metadata->method('chooseLinearTypeLabelGroupId')->willReturn('group-1');
        $metadata->method('buildLinearBranchPrefixMap')->willReturn(null);

        $patches = $this->createPrompts($this->promptAnswering([]), $metadata)->patches(
            new ProjectInitTrackers(jira: false, linear: true, linearTeamKey: false),
            [],
            ['linearTeamKey' => 'ENG'],
            new WorkflowRecorder(),
        );

        $this->assertSame(['linearTypeLabelGroupId' => 'group-1'], $patches);
    }

    public function testLinearFieldsSkipLabelGroupWhenNoneChosen(): void
    {
        $metadata = $this->createMock(ProjectMetadataPromptService::class);
        $metadata->method('chooseLinearStartStateId')->willReturn('state-1');
        $metadata->method('chooseLinearTypeLabelGroupId')->willReturn(null);
        $metadata->expects($this->never())->method('buildLinearBranchPrefixMap');

        $patches = $this->createPrompts($this->promptAnswering([]), $metadata)->patches(
            new ProjectInitTrackers(jira: false, linear: true, linearTeamKey: false),
            [],
            ['linearTeamKey' => 'ENG'],
            new WorkflowRecorder(),
        );

        $this->assertSame(['linearStartStateId' => 'state-1'], $patches);
    }

    public function testLinearFieldsAreSkippedWithoutScopeKey(): void
    {
        $metadata = $this->createMock(ProjectMetadataPromptService::class);
        $metadata->expects($this->never())->method('chooseLinearStartStateId');

        $patches = $this->createPrompts($this->promptAnswering([]), $metadata)->patches(
            new ProjectInitTrackers(jira: false, linear: true, linearTeamKey: false),
            [],
            [],
            new WorkflowRecorder(),
        );

        $this->assertSame([], $patches);
    }

    public function testLinearTeamKeyIsSkippedWithoutProjectKey(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('ask');

        $patches = $this->createPrompts($prompt)->patches(
            new ProjectInitTrackers(jira: false, linear: false, linearTeamKey: true),
            [],
            [],
            new WorkflowRecorder(),
        );

        $this->assertSame([], $patches);
    }

    public function testLinearTeamKeySkipsAnswerEqualToProjectKeyWhenUnset(): void
    {
        $patches = $this->createPrompts($this->promptAnswering([
            'config.project_init.prompt_linear_team_key' => 'sci',
        ]))->patches(
            new ProjectInitTrackers(jira: false, linear: false, linearTeamKey: true),
            [],
            ['projectKey' => 'SCI'],
            new WorkflowRecorder(),
        );

        $this->assertSame([], $patches);
    }

    public function testLinearTeamKeyUsesExistingValueAsDefaultAndSkipsUnchangedAnswer(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with($this->anything(), 'ENG')
            ->willReturn('ENG');

        $patches = $this->createPrompts($prompt)->patches(
            new ProjectInitTrackers(jira: false, linear: false, linearTeamKey: true),
            [],
            ['projectKey' => 'SCI', 'linearTeamKey' => 'ENG'],
            new WorkflowRecorder(),
        );

        $this->assertSame([], $patches);
    }

    public function testLinearTeamKeyReturnsEmptyWhenUserSkips(): void
    {
        $patches = $this->createPrompts($this->promptAnswering([
            'config.project_init.prompt_linear_team_key' => '',
        ]))->patches(
            new ProjectInitTrackers(jira: false, linear: false, linearTeamKey: true),
            [],
            ['projectKey' => 'SCI'],
            new WorkflowRecorder(),
        );

        $this->assertSame([], $patches);
    }
}
