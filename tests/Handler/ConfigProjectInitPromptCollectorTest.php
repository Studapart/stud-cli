<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\WorkflowRecorder;
use App\Handler\ConfigProjectInitPromptCollector;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use App\Service\GitTokenPromptResolver;
use App\Service\Prompt\PromptInterface;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class ConfigProjectInitPromptCollectorTest extends TestCase
{
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

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $prompt,
            new GitTokenPromptResolver()
        );

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

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $prompt,
            new GitTokenPromptResolver()
        );

        $this->assertSame(
            [
                'projectKey' => 'SCI',
                'gitProvider' => 'github',
            ],
            $collector->collect(new WorkflowRecorder())
        );
    }

    public function testCollectLogsErrorWhenTransitionIdIsNotNumeric(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('readProjectConfig')->willReturn([]);

        $gitSetup = $this->createMock(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn(null);

        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturn('bad-transition');

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturnOnConsecutiveCalls(
            '',
            '',
            '',
            'not-a-number',
            '',
            ''
        );
        $prompt->method('choice')->willReturn('github');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $prompt,
            new GitTokenPromptResolver()
        );

        $recorder = new WorkflowRecorder();
        $this->assertSame(['gitProvider' => 'github'], $collector->collect($recorder));
        $hasError = false;
        foreach ($recorder->getEntries() as $entry) {
            if ($entry->type === 'error') {
                $hasError = true;

                break;
            }
        }
        $this->assertTrue($hasError);
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
            '',
            'develop',
            ''
        );
        $prompt->method('choice')->willReturn('github');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $prompt,
            new GitTokenPromptResolver()
        );

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

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $prompt,
            new GitTokenPromptResolver()
        );

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

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $prompt,
            new GitTokenPromptResolver()
        );

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

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $prompt,
            new GitTokenPromptResolver()
        );

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
            '',
            'jira',
            '  myspace  ',
            '42',
            '',
            'https://gitlab.example/'
        );
        $prompt->method('choice')->willReturn('gitlab');
        $prompt->method('askHidden')->willReturnOnConsecutiveCalls('gh-secret', 'gl-secret');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $prompt,
            new GitTokenPromptResolver()
        );

        $this->assertSame(
            [
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

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $prompt,
            new GitTokenPromptResolver()
        );

        $this->assertSame([], $collector->collect(new WorkflowRecorder()));
    }
}
