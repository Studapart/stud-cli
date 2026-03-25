<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\ConfigProjectInitPromptCollector;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use App\Service\GitTokenPromptResolver;
use App\Service\Logger;
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

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('section');
        $logger->expects($this->once())->method('text');
        $logger->method('ask')->willReturn('');
        $logger->method('askHidden')->willReturn('');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $logger,
            new GitTokenPromptResolver()
        );

        $this->assertSame([], $collector->collect());
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

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->method('ask')->willReturnOnConsecutiveCalls(
            'SCI',
            '',
            '',
            '',
            '',
            ''
        );
        $logger->method('choice')->willReturn('github');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $logger,
            new GitTokenPromptResolver()
        );

        $this->assertSame(
            [
                'projectKey' => 'SCI',
                'gitProvider' => 'github',
            ],
            $collector->collect()
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

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->method('ask')->willReturnOnConsecutiveCalls(
            '',
            '',
            '',
            'not-a-number',
            '',
            ''
        );
        $logger->method('choice')->willReturn('github');
        $logger->expects($this->once())->method('error');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $logger,
            new GitTokenPromptResolver()
        );

        $this->assertSame(['gitProvider' => 'github'], $collector->collect());
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

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->expects($this->atLeastOnce())->method('note');
        $logger->method('ask')->willReturnOnConsecutiveCalls(
            '',
            '',
            '',
            '',
            'develop',
            ''
        );
        $logger->method('choice')->willReturn('github');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $logger,
            new GitTokenPromptResolver()
        );

        $this->assertSame(
            ['baseBranch' => 'develop', 'gitProvider' => 'github'],
            $collector->collect()
        );
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

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->expects($this->atLeastOnce())->method('note');
        $logger->method('ask')->willReturnOnConsecutiveCalls(
            '',
            '',
            '',
            '',
            '',
            ''
        );
        $logger->method('choice')->willReturn('gitlab');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $logger,
            new GitTokenPromptResolver()
        );

        $this->assertSame(['gitProvider' => 'gitlab'], $collector->collect());
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

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->method('note');
        $logger->method('ask')->willReturn('');
        $logger->method('askHidden')->willReturnOnConsecutiveCalls('.', '');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $logger,
            new GitTokenPromptResolver()
        );

        $this->assertSame([], $collector->collect());
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

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->method('note');
        $logger->method('ask')->willReturn('');
        $logger->method('askHidden')->willReturnOnConsecutiveCalls('', '.');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $logger,
            new GitTokenPromptResolver()
        );

        $this->assertSame([], $collector->collect());
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

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->method('note');
        $logger->method('ask')->willReturnOnConsecutiveCalls(
            '',
            'jira',
            '  myspace  ',
            '42',
            '',
            'https://gitlab.example/'
        );
        $logger->method('choice')->willReturn('gitlab');
        $logger->method('askHidden')->willReturnOnConsecutiveCalls('gh-secret', 'gl-secret');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $logger,
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
            $collector->collect()
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

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->expects($this->never())->method('choice');
        $logger->method('ask')->willReturn('');
        $logger->method('askHidden')->willReturn('');

        $collector = new ConfigProjectInitPromptCollector(
            $gitRepository,
            $gitSetup,
            $translator,
            $logger,
            new GitTokenPromptResolver()
        );

        $this->assertSame([], $collector->collect());
    }
}
