<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\WorkflowRecorder;
use App\Handler\ConfigProjectInitPromptCollector;
use App\Service\FileSystem;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use App\Service\GitTokenPromptResolver;
use App\Service\GlobalConfigProviderResolver;
use App\Service\ProjectMetadataPromptService;
use App\Service\Prompt\PromptInterface;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class ConfigProjectInitPromptCollectorTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $globalConfig
     */
    private function createCollector(
        GitRepository $gitRepository,
        PromptInterface $prompt,
        GitSetupService $gitSetup,
        ?array $globalConfig = null,
        ?ProjectMetadataPromptService $metadataPrompts = null,
    ): ConfigProjectInitPromptCollector {
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->method('fileExists')->willReturn($globalConfig !== null);
        if ($globalConfig !== null) {
            $fileSystem->method('parseFile')->willReturn($globalConfig);
        }

        return new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $this->createMock(TranslationService::class),
            $prompt,
            new GitTokenPromptResolver(),
            $fileSystem,
            '/tmp/global-config.yml',
            new GlobalConfigProviderResolver(),
            $metadataPrompts ?? $this->createDefaultMetadataPromptsMock(),
        );
    }

    private function createDefaultMetadataPromptsMock(): ProjectMetadataPromptService
    {
        $metadataPrompts = $this->createMock(ProjectMetadataPromptService::class);
        $metadataPrompts->method('chooseJiraTransitionId')->willReturn(null);
        $metadataPrompts->method('chooseLinearStartStateId')->willReturn(null);
        $metadataPrompts->method('chooseLinearTypeLabelGroupId')->willReturn(null);
        $metadataPrompts->method('buildLinearBranchPrefixMap')->willReturn(null);

        return $metadataPrompts;
    }

    public function testCollectReturnsEmptyWhenUserSkipsAllOptionalUpdates(): void
    {
        $existing = [
            'gitProvider' => 'github',
            'projectKey' => 'SCI',
            'baseBranch' => 'develop',
            'transitionId' => 5,
            'JIRA_DEFAULT_PROJECT' => 'SCI',
            'CONFLUENCE_DEFAULT_SPACE' => 'DOC',
            'gitlabInstanceUrl' => 'https://gitlab.example',
            'githubToken' => 'gh',
            'gitlabToken' => 'gl',
        ];

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn($existing);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('msg');

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturn('');
        $prompt->method('askHidden')->willReturn('');

        $collector = $this->createCollector($gitRepository, $prompt, $gitSetup);

        $recorder = new WorkflowRecorder();
        $this->assertSame([], $collector->collect($recorder));
        $this->assertNotEmpty($recorder->getEntries());
    }

    public function testCollectMergesProjectKeyAndGitProviderFromPrompts(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('msg');

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturnOnConsecutiveCalls(
            'SCI',
            '',
            '',
            '',
            '',
            ''
        );
        $prompt->method('choice')->willReturn('github');

        $collector = $this->createCollector($gitRepository, $prompt, $gitSetup);

        $this->assertSame(
            [
                'projectKey' => 'SCI',
                'gitProvider' => 'github',
            ],
            $collector->collect(new WorkflowRecorder())
        );
    }

    public function testCollectSkipsTransitionWhenMetadataPickerReturnsNull(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturn('');
        $prompt->method('choice')->willReturn('github');

        $collector = $this->createCollector($gitRepository, $prompt, $gitSetup);

        $recorder = new WorkflowRecorder();
        $this->assertSame(['gitProvider' => 'github'], $collector->collect($recorder));
    }

    public function testCollectNotesDetectedBaseBranchWhenPresent(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn('main');

        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('note');

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturnOnConsecutiveCalls(
            '',
            '',
            '',
            'develop',
            ''
        );
        $prompt->method('choice')->willReturn('github');

        $collector = $this->createCollector($gitRepository, $prompt, $gitSetup);

        $recorder = new WorkflowRecorder();
        $this->assertSame(
            ['baseBranch' => 'develop', 'gitProvider' => 'github'],
            $collector->collect($recorder)
        );
        $hasNote = false;
        foreach ($recorder->getEntries() as $entry) {
            if ($entry->type === 'note') {
                $hasNote = true;

                break;
            }
        }
        $this->assertTrue($hasNote);
    }

    public function testCollectNotesDetectedGitProviderFromRemote(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn(['provider' => 'gitlab']);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('msg');

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturnOnConsecutiveCalls(
            '',
            '',
            '',
            '',
            '',
            ''
        );
        $prompt->method('choice')->willReturn('gitlab');

        $collector = $this->createCollector($gitRepository, $prompt, $gitSetup);

        $recorder = new WorkflowRecorder();
        $this->assertSame(['gitProvider' => 'gitlab'], $collector->collect($recorder));
        $hasNote = false;
        foreach ($recorder->getEntries() as $entry) {
            if ($entry->type === 'note') {
                $hasNote = true;

                break;
            }
        }
        $this->assertTrue($hasNote);
    }

    public function testCollectSkipsGithubTokenWhenUserEntersDotToKeepExisting(): void
    {
        $existing = [
            'gitProvider' => 'github',
            'githubToken' => 'secret',
            'gitlabToken' => '',
        ];

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn($existing);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('msg');

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturn('');
        $prompt->method('askHidden')->willReturnOnConsecutiveCalls('.', '');

        $collector = $this->createCollector($gitRepository, $prompt, $gitSetup);

        $this->assertSame([], $collector->collect(new WorkflowRecorder()));
    }

    public function testCollectSkipsGitlabTokenWhenUserEntersDotToKeepExisting(): void
    {
        $existing = [
            'gitProvider' => 'github',
            'gitlabToken' => 'glpat',
            'githubToken' => '',
        ];

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn($existing);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('msg');

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturn('');
        $prompt->method('askHidden')->willReturnOnConsecutiveCalls('', '.');

        $collector = $this->createCollector($gitRepository, $prompt, $gitSetup);

        $this->assertSame([], $collector->collect(new WorkflowRecorder()));
    }

    public function testCollectReturnsJiraConfluenceGitlabUrlAndTokensWhenProvided(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('msg');

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturnOnConsecutiveCalls(
            'SCI',
            'jira',
            '  myspace  ',
            '',
            'https://gitlab.example/'
        );
        $prompt->method('choice')->willReturn('gitlab');
        $prompt->method('askHidden')->willReturnOnConsecutiveCalls('gh-secret', 'gl-secret');

        $metadataPrompts = $this->createMock(ProjectMetadataPromptService::class);
        $metadataPrompts->expects($this->once())
            ->method('chooseJiraTransitionId')
            ->willReturn(42);

        $collector = $this->createCollector($gitRepository, $prompt, $gitSetup, null, $metadataPrompts);

        $this->assertSame(
            [
                'projectKey' => 'SCI',
                'jiraDefaultProject' => 'JIRA',
                'confluenceDefaultSpace' => 'myspace',
                'transitionId' => 42,
                'gitProvider' => 'gitlab',
                'gitlabInstanceUrl' => 'https://gitlab.example/',
                'githubToken' => 'gh-secret',
                'gitlabToken' => 'gl-secret',
            ],
            $collector->collect(new WorkflowRecorder())
        );
    }

    public function testPromptGitProviderReturnsEarlyWhenAlreadyValid(): void
    {
        $existing = [
            'gitProvider' => 'gitlab',
            'projectKey' => 'X',
        ];

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn($existing);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('msg');

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('choice');
        $prompt->method('ask')->willReturn('');
        $prompt->method('askHidden')->willReturn('');

        $collector = $this->createCollector($gitRepository, $prompt, $gitSetup);

        $this->assertSame([], $collector->collect(new WorkflowRecorder()));
    }

    public function testCollectPromptsWorkItemProviderWhenGlobalHasBothPmProviders(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('choice')
            ->willReturnOnConsecutiveCalls('linear', 'github');
        $prompt->method('ask')->willReturn('');
        $prompt->method('askHidden')->willReturn('');

        $collector = $this->createCollector(
            $gitRepository,
            $prompt,
            $gitSetup,
            ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
        );

        $this->assertSame(
            ['workItemProvider' => 'linear', 'gitProvider' => 'github'],
            $collector->collect(new WorkflowRecorder()),
        );
    }

    public function testCollectPromptsLinearFieldsWhenGlobalProviderIsLinearOnly(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('choice')->willReturn('github');
        $prompt->method('ask')->willReturnOnConsecutiveCalls('SCI', '', '');
        $prompt->method('askHidden')->willReturn('');

        $metadataPrompts = $this->createMock(ProjectMetadataPromptService::class);
        $metadataPrompts->expects($this->once())
            ->method('chooseLinearStartStateId')
            ->willReturn('state-1');
        $metadataPrompts->expects($this->once())
            ->method('chooseLinearTypeLabelGroupId')
            ->willReturn('group-1');
        $metadataPrompts->expects($this->once())
            ->method('buildLinearBranchPrefixMap')
            ->with($this->anything(), 'SCI', $this->anything(), 'group-1')
            ->willReturn(['Story' => 'feat']);

        $collector = $this->createCollector(
            $gitRepository,
            $prompt,
            $gitSetup,
            ['WORK_ITEM_PROVIDERS' => ['linear']],
            $metadataPrompts,
        );

        $this->assertSame(
            [
                'projectKey' => 'SCI',
                'linearStartStateId' => 'state-1',
                'linearTypeLabelGroupId' => 'group-1',
                'linearTypeBranchPrefixes' => ['Story' => 'feat'],
                'gitProvider' => 'github',
            ],
            $collector->collect(new WorkflowRecorder()),
        );
    }

    public function testCollectInfersGlobalWorkItemProvidersFromStoredCredentials(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('choice')
            ->willReturnOnConsecutiveCalls('auto', 'github');
        $prompt->method('ask')->willReturn('');
        $prompt->method('askHidden')->willReturn('');

        $collector = $this->createCollector(
            $gitRepository,
            $prompt,
            $gitSetup,
            ['JIRA_URL' => 'https://jira.example.com', 'LINEAR_API_KEY' => 'lin'],
        );

        $this->assertSame(
            ['workItemProvider' => 'auto', 'gitProvider' => 'github'],
            $collector->collect(new WorkflowRecorder()),
        );
    }

    public function testCollectDefaultsWorkItemProviderWhenStoredValueInvalid(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn(['workItemProvider' => 'invalid']);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('choice')
            ->willReturnOnConsecutiveCalls('auto', 'github');
        $prompt->method('ask')->willReturn('');
        $prompt->method('askHidden')->willReturn('');

        $collector = $this->createCollector(
            $gitRepository,
            $prompt,
            $gitSetup,
            ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
        );

        $this->assertSame(
            ['workItemProvider' => 'auto', 'gitProvider' => 'github'],
            $collector->collect(new WorkflowRecorder()),
        );
    }

    public function testCollectFallsBackWhenGlobalConfigCannotBeParsed(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $fileSystem = $this->createMock(\App\Service\FileSystem::class);
        $fileSystem->method('fileExists')->willReturn(true);
        $fileSystem->method('parseFile')->willThrowException(new \RuntimeException('bad yaml'));

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('choice')->willReturn('github');
        $prompt->method('ask')->willReturn('');
        $prompt->method('askHidden')->willReturn('');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $this->createMock(TranslationService::class),
            $prompt,
            new GitTokenPromptResolver(),
            $fileSystem,
            '/tmp/global-config.yml',
            new GlobalConfigProviderResolver(),
            $this->createDefaultMetadataPromptsMock(),
        );

        $this->assertSame(['gitProvider' => 'github'], $collector->collect(new WorkflowRecorder()));
    }

    public function testCollectUsesExistingLinearFieldDefaultsWhenUpdating(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([
            'linearStartStateId' => 'existing-state',
            'linearTypeLabelGroupId' => 'existing-group',
        ]);
        $gitRepository->method('parseGitUrl')->with('origin')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('choice')->willReturn('github');
        $prompt->method('ask')->willReturnOnConsecutiveCalls('SCI', '', '');
        $prompt->method('askHidden')->willReturn('');

        $metadataPrompts = $this->createMock(ProjectMetadataPromptService::class);
        $metadataPrompts->expects($this->once())
            ->method('chooseLinearStartStateId')
            ->willReturn('new-state');
        $metadataPrompts->expects($this->once())
            ->method('chooseLinearTypeLabelGroupId')
            ->willReturn('new-group');
        $metadataPrompts->method('buildLinearBranchPrefixMap')->willReturn(null);

        $collector = $this->createCollector(
            $gitRepository,
            $prompt,
            $gitSetup,
            ['WORK_ITEM_PROVIDERS' => ['linear']],
            $metadataPrompts,
        );

        $this->assertSame(
            [
                'projectKey' => 'SCI',
                'linearStartStateId' => 'new-state',
                'linearTypeLabelGroupId' => 'new-group',
                'gitProvider' => 'github',
            ],
            $collector->collect(new WorkflowRecorder()),
        );
    }

    public function testResolveEffectiveWorkItemProviderDefaultsWhenUnset(): void
    {
        $collector = $this->createCollector(
            $this->createMock(GitRepository::class),
            $this->createMock(PromptInterface::class),
            $this->createMock(GitSetupService::class),
            ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
        );

        $method = new \ReflectionMethod(ConfigProjectInitPromptCollector::class, 'resolveEffectiveWorkItemProvider');
        $method->setAccessible(true);

        $this->assertSame(
            'auto',
            $method->invoke($collector, [], ['jira', 'linear']),
        );
    }

    public function testMergeProjectConfigSkipsUnknownInputKeys(): void
    {
        $collector = $this->createCollector(
            $this->createMock(GitRepository::class),
            $this->createMock(PromptInterface::class),
            $this->createMock(GitSetupService::class),
        );

        $method = new \ReflectionMethod(ConfigProjectInitPromptCollector::class, 'mergeProjectConfig');
        $method->setAccessible(true);

        $this->assertSame(
            ['projectKey' => 'SCI'],
            $method->invoke($collector, [], ['unknownKey' => 'x', 'projectKey' => 'SCI']),
        );
    }
}
