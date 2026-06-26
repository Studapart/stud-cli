<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\DTO\StateChange;
use App\DTO\WorkflowRecorder;
use App\Service\BranchNameGenerator;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerPortSupplier;
use App\Service\MessageRenderer;
use App\Service\ProjectMetadataPromptService;
use App\Service\ProjectsWorkflowNormalizer;
use App\Service\Prompt\PromptInterface;
use App\Service\TranslationService;
use App\Tests\CommandTestCase;

class ProjectMetadataPromptServiceTest extends CommandTestCase
{
    public function testChooseJiraTransitionIdReturnsSelectedTransition(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())
            ->method('listProjectStateChanges')
            ->with('SCI')
            ->willReturn([new StateChange('42', 'Start Progress', 'In Progress')]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->willReturn('Start Progress (ID: 42)');

        $service = $this->createService($prompt, port: $port);
        $result = $service->chooseJiraTransitionId(new WorkflowRecorder(), 'SCI', []);

        $this->assertSame(42, $result);
    }

    public function testChooseJiraTransitionIdReturnsNullWhenUserSkips(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())
            ->method('listProjectStateChanges')
            ->willReturn([new StateChange('42', 'Start Progress', 'In Progress')]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->willReturn('Skip (leave unset)');

        $service = $this->createService($prompt, port: $port);
        $result = $service->chooseJiraTransitionId(new WorkflowRecorder(), 'SCI', []);

        $this->assertNull($result);
    }

    public function testChooseJiraTransitionIdLogsWarningWhenWorkflowFetchFails(): void
    {
        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            resolveError: MessageRef::key('project.workflow.error_ambiguous_provider'),
        );
        $recorder = new WorkflowRecorder();

        $result = $service->chooseJiraTransitionId($recorder, 'SCI', []);

        $this->assertNull($result);
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseLinearStartStateIdReturnsSelectedState(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())
            ->method('listProjectStateChanges')
            ->with('SCI')
            ->willReturn([new StateChange('state-1', 'In Progress', null, 'started')]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->willReturn('In Progress (ID: state-1)');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            port: $port,
            provider: 'linear',
        );

        $this->assertSame(
            'state-1',
            $service->chooseLinearStartStateId(new WorkflowRecorder(), 'SCI', []),
        );
    }

    public function testChooseLinearTypeLabelGroupIdReturnsSelectedGroup(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())
            ->method('listLabelGroups')
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
            port: $port,
            provider: 'linear',
        );

        $this->assertSame(
            'group-1',
            $service->chooseLinearTypeLabelGroupId(new WorkflowRecorder(), 'SCI', []),
        );
    }

    public function testBuildLinearBranchPrefixMapReturnsMapWhenConfirmed(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->once())
            ->method('listLabelGroups')
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
            port: $port,
            provider: 'linear',
        );

        $this->assertSame(
            ['Bug' => 'fix', 'Story' => 'feat'],
            $service->buildLinearBranchPrefixMap(new WorkflowRecorder(), 'SCI', [], 'group-1'),
        );
    }

    public function testBuildLinearBranchPrefixMapReturnsNullWhenNotConfirmed(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listLabelGroups')->willReturn([
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
            port: $port,
            provider: 'linear',
        );

        $this->assertNull(
            $service->buildLinearBranchPrefixMap(new WorkflowRecorder(), 'SCI', [], 'group-1'),
        );
    }

    public function testChooseJiraTransitionIdReturnsNullWhenNoJiraTransitionsListed(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listProjectStateChanges')->willReturn([
            new StateChange('state-1', 'Todo', null, 'unstarted'),
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('choice');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            port: $port,
            provider: 'linear',
        );

        $recorder = new WorkflowRecorder();
        $this->assertNull($service->chooseJiraTransitionId($recorder, 'SCI', []));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseJiraTransitionIdReturnsNullWhenChoiceHasInvalidFormat(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listProjectStateChanges')->willReturn([
            new StateChange('42', 'Start Progress', 'In Progress'),
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('choice')->willReturn('broken selection');

        $service = $this->createService($prompt, port: $port);
        $this->assertNull($service->chooseJiraTransitionId(new WorkflowRecorder(), 'SCI', []));
    }

    public function testChooseLinearStartStateIdLogsWarningWhenWorkflowFetchFails(): void
    {
        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            resolveError: MessageRef::key('project.workflow.error_ambiguous_provider'),
        );
        $recorder = new WorkflowRecorder();

        $this->assertNull($service->chooseLinearStartStateId($recorder, 'SCI', []));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseLinearStartStateIdReturnsNullWhenNoLinearStatesListed(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listProjectStateChanges')->willReturn([
            new StateChange('11', 'Start', 'In Progress'),
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('choice');

        $service = $this->createService($prompt, port: $port);
        $recorder = new WorkflowRecorder();
        $this->assertNull($service->chooseLinearStartStateId($recorder, 'SCI', []));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseLinearTypeLabelGroupIdLogsWarningWhenLabelsFetchFails(): void
    {
        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            resolveError: MessageRef::key('project.workflow.error_ambiguous_provider'),
        );
        $recorder = new WorkflowRecorder();

        $this->assertNull($service->chooseLinearTypeLabelGroupId($recorder, 'SCI', []));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testChooseLinearTypeLabelGroupIdReturnsNullWhenNoGroups(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listLabelGroups')->willReturn([]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('choice');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            port: $port,
            provider: 'linear',
        );

        $this->assertNull($service->chooseLinearTypeLabelGroupId(new WorkflowRecorder(), 'SCI', []));
    }

    public function testBuildLinearBranchPrefixMapLogsWarningWhenLabelsFetchFails(): void
    {
        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            resolveError: MessageRef::key('project.workflow.error_ambiguous_provider'),
        );
        $recorder = new WorkflowRecorder();

        $this->assertNull($service->buildLinearBranchPrefixMap($recorder, 'SCI', [], 'group-1'));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testBuildLinearBranchPrefixMapWarnsWhenSelectedGroupHasNoChildLabels(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listLabelGroups')->willReturn([
            ['id' => 'group-1', 'name' => 'Type', 'labels' => []],
        ]);

        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            port: $port,
            provider: 'linear',
        );
        $recorder = new WorkflowRecorder();

        $this->assertNull($service->buildLinearBranchPrefixMap($recorder, 'SCI', [], 'group-1'));
        $this->assertTrue($this->recorderHasWarning($recorder));
    }

    public function testBuildLinearBranchPrefixMapReturnsNullWhenAllPrefixPromptsSkipped(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listLabelGroups')->willReturn([
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
            port: $port,
            provider: 'linear',
        );

        $this->assertNull($service->buildLinearBranchPrefixMap(new WorkflowRecorder(), 'SCI', [], 'group-1'));
    }

    public function testChooseLinearTypeLabelGroupIdDefaultsToExistingGroupId(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listLabelGroups')->willReturn([
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
            port: $port,
            provider: 'linear',
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
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listProjectStateChanges')->willReturn([
            new StateChange('state-1', 'In Progress', null, 'started'),
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
            port: $port,
            provider: 'linear',
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
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listProjectStateChanges')->willReturn([
            new StateChange('42', 'Start Progress', 'In Progress'),
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

        $service = $this->createService(
            $prompt,
            port: $port,
            messageRenderer: $renderer,
        );

        $this->assertNull($service->chooseJiraTransitionId(new WorkflowRecorder(), 'SCI', []));
    }

    public function testChooseLinearTypeLabelGroupIdIgnoresInvalidExistingGroupId(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listLabelGroups')->willReturn([
            ['id' => 'group-1', 'name' => 'Type', 'labels' => []],
        ]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('choice')->willReturn('Type (ID: group-1)');

        $service = $this->createService(
            $prompt,
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            port: $port,
            provider: 'linear',
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
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('listLabelGroups')->willReturn([
            ['id' => 'group-1', 'name' => 'Type', 'labels' => [['id' => 'label-1', 'name' => 'Bug']]],
            ['id' => 'group-2', 'name' => 'Priority', 'labels' => []],
        ]);

        $service = $this->createService(
            $this->createMock(PromptInterface::class),
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin'],
            port: $port,
            provider: 'linear',
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
     */
    private function createService(
        PromptInterface $prompt,
        array $globalConfig = ['WORK_ITEM_PROVIDERS' => ['jira']],
        ?IssueTrackerPort $port = null,
        string $provider = 'jira',
        ?MessageRef $resolveError = null,
        ?MessageRenderer $messageRenderer = null,
    ): ProjectMetadataPromptService {
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        if ($resolveError !== null) {
            $supplier->method('resolve')->willReturn(['ok' => false, 'error' => $resolveError]);
        } elseif ($port !== null) {
            $supplier->method('resolve')->willReturn(['ok' => true, 'provider' => $provider, 'port' => $port]);
        } else {
            $supplier->method('resolve')->willReturn([
                'ok' => false,
                'error' => MessageRef::key('project.workflow.error_ambiguous_provider'),
            ]);
        }

        return new ProjectMetadataPromptService(
            $supplier,
            new ProjectsWorkflowNormalizer(),
            $globalConfig,
            $prompt,
            $messageRenderer,
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
