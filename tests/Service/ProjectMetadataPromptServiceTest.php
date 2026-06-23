<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\WorkflowRecorder;
use App\Service\BranchNameGenerator;
use App\Service\LinearMetadataClient;
use App\Service\MessageRenderer;
use App\Service\ProjectMetadataPromptService;
use App\Service\ProjectsWorkflowNormalizer;
use App\Service\Prompt\PromptInterface;
use App\Service\TranslationService;
use App\Service\WorkItemProviderResolver;
use App\Tests\CommandTestCase;

class ProjectMetadataPromptServiceTest extends CommandTestCase
{
    public function testChooseJiraTransitionIdReturnsSelectedTransition(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjectTransitions')
            ->with('SCI')
            ->willReturn([
                ['id' => 42, 'name' => 'Start Progress', 'to' => ['name' => 'In Progress']],
            ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->willReturn('Start Progress (ID: 42)');

        $service = $this->createService($prompt);
        $result = $service->chooseJiraTransitionId(new WorkflowRecorder(), 'SCI', []);

        $this->assertSame(42, $result);
    }

    public function testChooseJiraTransitionIdReturnsNullWhenUserSkips(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjectTransitions')
            ->willReturn([
                ['id' => 42, 'name' => 'Start Progress', 'to' => ['name' => 'In Progress']],
            ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->willReturn('Skip (leave unset)');

        $service = $this->createService($prompt);
        $result = $service->chooseJiraTransitionId(new WorkflowRecorder(), 'SCI', []);

        $this->assertNull($result);
    }

    public function testChooseJiraTransitionIdLogsWarningWhenWorkflowFetchFails(): void
    {
        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
        );
        $recorder = new WorkflowRecorder();

        $result = $service->chooseJiraTransitionId($recorder, 'SCI', []);

        $this->assertNull($result);
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseLinearStartStateIdReturnsSelectedState(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->expects($this->once())
            ->method('getTeamWorkflowStates')
            ->with('SCI')
            ->willReturn([
                ['id' => 'state-1', 'name' => 'In Progress', 'type' => 'started'],
            ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->willReturn('In Progress (ID: state-1)');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $this->assertSame(
            'state-1',
            $service->chooseLinearStartStateId(new WorkflowRecorder(), 'SCI', []),
        );
    }

    public function testChooseLinearTypeLabelGroupIdReturnsSelectedGroup(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', true)
            ->willReturn([
                [
                    'id' => 'group-1',
                    'name' => 'Type',
                    'labels' => [['id' => 'label-1', 'name' => 'Bug']],
                ],
            ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->willReturn('Type (ID: group-1)');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $this->assertSame(
            'group-1',
            $service->chooseLinearTypeLabelGroupId(new WorkflowRecorder(), 'SCI', []),
        );
    }

    public function testBuildLinearBranchPrefixMapReturnsMapWhenConfirmed(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', true)
            ->willReturn([
                [
                    'id' => 'group-1',
                    'name' => 'Type',
                    'labels' => [
                        ['id' => 'label-1', 'name' => 'Bug'],
                        ['id' => 'label-2', 'name' => 'Story'],
                    ],
                ],
            ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('ask')
            ->willReturnOnConsecutiveCalls('fix', 'feat');
        $prompt->expects($this->once())
            ->method('confirm')
            ->willReturn(true);

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $this->assertSame(
            ['Bug' => 'fix', 'Story' => 'feat'],
            $service->buildLinearBranchPrefixMap(new WorkflowRecorder(), 'SCI', [], 'group-1'),
        );
    }

    public function testBuildLinearBranchPrefixMapReturnsNullWhenNotConfirmed(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->method('getTeamLabelGroups')->willReturn([
            [
                'id' => 'group-1',
                'name' => 'Type',
                'labels' => [['id' => 'label-1', 'name' => 'Bug']],
            ],
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturn('fix');
        $prompt->method('confirm')->willReturn(false);

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $this->assertNull(
            $service->buildLinearBranchPrefixMap(new WorkflowRecorder(), 'SCI', [], 'group-1'),
        );
    }

    public function testChooseJiraTransitionIdReturnsNullWhenNoJiraTransitionsListed(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->method('getTeamWorkflowStates')->willReturn([
            ['id' => 'state-1', 'name' => 'Todo', 'type' => 'unstarted'],
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('choice');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $recorder = new WorkflowRecorder();
        $this->assertNull($service->chooseJiraTransitionId($recorder, 'SCI', []));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseJiraTransitionIdReturnsNullWhenChoiceHasInvalidFormat(): void
    {
        $this->jiraService->method('getProjectTransitions')->willReturn([
            ['id' => 42, 'name' => 'Start Progress', 'to' => ['name' => 'In Progress']],
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('choice')->willReturn('broken selection');

        $service = $this->createService($prompt);
        $this->assertNull($service->chooseJiraTransitionId(new WorkflowRecorder(), 'SCI', []));
    }

    public function testChooseLinearStartStateIdLogsWarningWhenWorkflowFetchFails(): void
    {
        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
        );
        $recorder = new WorkflowRecorder();

        $this->assertNull($service->chooseLinearStartStateId($recorder, 'SCI', []));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseLinearStartStateIdReturnsNullWhenNoLinearStatesListed(): void
    {
        $this->jiraService->method('getProjectTransitions')->willReturn([
            ['id' => 11, 'name' => 'Start', 'to' => ['name' => 'In Progress']],
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('choice');

        $service = $this->createService($prompt);
        $recorder = new WorkflowRecorder();
        $this->assertNull($service->chooseLinearStartStateId($recorder, 'SCI', []));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseLinearTypeLabelGroupIdLogsWarningWhenLabelsFetchFails(): void
    {
        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
        );
        $recorder = new WorkflowRecorder();

        $this->assertNull($service->chooseLinearTypeLabelGroupId($recorder, 'SCI', []));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseLinearTypeLabelGroupIdReturnsNullWhenNoGroups(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->method('getTeamLabelGroups')->willReturn([]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('choice');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $this->assertNull($service->chooseLinearTypeLabelGroupId(new WorkflowRecorder(), 'SCI', []));
    }

    public function testBuildLinearBranchPrefixMapLogsWarningWhenLabelsFetchFails(): void
    {
        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
        );
        $recorder = new WorkflowRecorder();

        $this->assertNull($service->buildLinearBranchPrefixMap($recorder, 'SCI', [], 'group-1'));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testBuildLinearBranchPrefixMapWarnsWhenSelectedGroupHasNoChildLabels(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->method('getTeamLabelGroups')->willReturn([
            ['id' => 'group-1', 'name' => 'Type', 'labels' => []],
        ]);

        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );
        $recorder = new WorkflowRecorder();

        $this->assertNull($service->buildLinearBranchPrefixMap($recorder, 'SCI', [], 'group-1'));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testBuildLinearBranchPrefixMapReturnsNullWhenAllPrefixPromptsSkipped(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->method('getTeamLabelGroups')->willReturn([
            [
                'id' => 'group-1',
                'name' => 'Type',
                'labels' => [['id' => 'label-1', 'name' => 'Bug']],
            ],
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturn('');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $this->assertNull($service->buildLinearBranchPrefixMap(new WorkflowRecorder(), 'SCI', [], 'group-1'));
    }

    public function testChooseLinearTypeLabelGroupIdDefaultsToExistingGroupId(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->method('getTeamLabelGroups')->willReturn([
            ['id' => 'group-1', 'name' => 'Type', 'labels' => []],
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->with(
                $this->anything(),
                $this->anything(),
                'Type (ID: group-1)',
            )
            ->willReturn('Type (ID: group-1)');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $this->assertSame(
            'group-1',
            $service->chooseLinearTypeLabelGroupId(
                new WorkflowRecorder(),
                'SCI',
                ['linearTypeLabelGroupId' => 'group-1'],
            ),
        );
    }

    public function testChooseWorkflowItemIdDefaultsToExistingLinearStartState(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->method('getTeamWorkflowStates')->willReturn([
            ['id' => 'state-1', 'name' => 'In Progress', 'type' => 'started'],
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->with(
                $this->anything(),
                $this->anything(),
                'In Progress (ID: state-1)',
            )
            ->willReturn('In Progress (ID: state-1)');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $this->assertSame(
            'state-1',
            $service->chooseLinearStartStateId(
                new WorkflowRecorder(),
                'SCI',
                ['linearStartStateId' => 'state-1'],
            ),
        );
    }

    public function testSkipChoiceLabelUsesMessageRendererWhenProvided(): void
    {
        $this->jiraService->method('getProjectTransitions')->willReturn([
            ['id' => 42, 'name' => 'Start Progress', 'to' => ['name' => 'In Progress']],
        ]);

        $translator = $this->createMock(TranslationService::class);
        $translator->method('render')->willReturn('Skip translated');
        $renderer = new MessageRenderer($translator);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->with(
                $this->anything(),
                ['Skip translated', 'Start Progress (ID: 42)'],
                'Skip translated',
            )
            ->willReturn('Skip translated');

        $service = new ProjectMetadataPromptService(
            $this->jiraService,
            null,
            new WorkItemProviderResolver(),
            new ProjectsWorkflowNormalizer(),
            ['WORK_ITEM_PROVIDERS' => ['jira']],
            $prompt,
            $renderer,
        );

        $this->assertNull($service->chooseJiraTransitionId(new WorkflowRecorder(), 'SCI', []));
    }

    public function testChooseLinearTypeLabelGroupIdIgnoresInvalidExistingGroupId(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->method('getTeamLabelGroups')->willReturn([
            ['id' => 'group-1', 'name' => 'Type', 'labels' => []],
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('choice')->willReturn('Type (ID: group-1)');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );

        $this->assertSame(
            'group-1',
            $service->chooseLinearTypeLabelGroupId(
                new WorkflowRecorder(),
                'SCI',
                ['linearTypeLabelGroupId' => 123],
            ),
        );
    }

    public function testBuildLinearBranchPrefixMapReturnsNullWhenGroupIdNotFound(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->method('getTeamLabelGroups')->willReturn([
            ['id' => 'group-1', 'name' => 'Type', 'labels' => [['id' => 'label-1', 'name' => 'Bug']]],
            ['id' => 'group-2', 'name' => 'Priority', 'labels' => []],
        ]);

        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            linearClient: $linearClient,
        );
        $recorder = new WorkflowRecorder();

        $this->assertNull($service->buildLinearBranchPrefixMap($recorder, 'SCI', [], 'missing-group'));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testDefaultPrefixForLabelNameUsesBranchNameGeneratorHeuristics(): void
    {
        $this->assertSame(BranchNameGenerator::PREFIX_FIX, ProjectMetadataPromptService::defaultPrefixForLabelName('Bug'));
        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, ProjectMetadataPromptService::defaultPrefixForLabelName('Story'));
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function createService(
        PromptInterface $prompt,
        array $globalConfig = ['WORK_ITEM_PROVIDERS' => ['jira']],
        ?LinearMetadataClient $linearClient = null,
        array $projectConfig = [],
    ): ProjectMetadataPromptService {
        unset($projectConfig);

        return new ProjectMetadataPromptService(
            $this->jiraService,
            $linearClient,
            new WorkItemProviderResolver(),
            new ProjectsWorkflowNormalizer(),
            $globalConfig,
            $prompt,
        );
    }

    private function recorderHasWarning(WorkflowRecorder $recorder): bool
    {
        foreach ($recorder->getEntries() as $entry) {
            if ($entry->type === 'warning') {
                return true;
            }
        }

        return false;
    }
}
