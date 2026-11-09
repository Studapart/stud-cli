<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Handler\ProjectGitPrompts;
use App\Service\GitRepository;
use App\Service\GitSetupService;
use App\Service\GitTokenPromptResolver;
use App\Service\Prompt\PromptInterface;
use PHPUnit\Framework\TestCase;

class ProjectGitPromptsTest extends TestCase
{
    private function createPrompts(
        PromptInterface $prompt,
        ?GitRepository $gitRepository = null,
        ?string $detectedBaseBranch = null,
    ): ProjectGitPrompts {
        $gitSetup = $this->createStub(GitSetupService::class);
        $gitSetup->method('detectDefaultBaseBranchName')->willReturn($detectedBaseBranch);

        return new ProjectGitPrompts(
            $gitRepository ?? $this->createStub(GitRepository::class),
            $gitSetup,
            $prompt,
            new GitTokenPromptResolver(),
        );
    }

    public function testBaseBranchPatchesNotesDetectedBranchAndUsesItAsDefault(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with(MessageRef::key('config.project_init.prompt_base_branch'), 'main')
            ->willReturn(' main ');
        $recorder = new WorkflowRecorder();

        $patches = $this->createPrompts($prompt, detectedBaseBranch: 'main')->baseBranchPatches([], $recorder);

        $this->assertSame(['baseBranch' => 'main'], $patches);
        $this->assertCount(1, $recorder->getEntries());
    }

    public function testBaseBranchPatchesPrefersStoredBranchAndSkipsBlankAnswer(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with(MessageRef::key('config.project_init.prompt_base_branch'), 'release')
            ->willReturn('  ');
        $recorder = new WorkflowRecorder();

        $patches = $this->createPrompts($prompt)->baseBranchPatches(['baseBranch' => 'release'], $recorder);

        $this->assertSame([], $patches);
        $this->assertSame([], $recorder->getEntries());
    }

    public function testBaseBranchPatchesDefaultsToDevelopWhenNothingStoredOrDetected(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with(MessageRef::key('config.project_init.prompt_base_branch'), 'develop')
            ->willReturn(null);

        $this->assertSame([], $this->createPrompts($prompt)->baseBranchPatches([], new WorkflowRecorder()));
    }

    public function testHostingPatchesPromptsProviderUrlAndTokens(): void
    {
        $gitRepository = $this->createStub(GitRepository::class);
        $gitRepository->method('parseGitUrl')->willReturn(['provider' => 'gitlab']);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->with(MessageRef::key('config.project_init.prompt_git_provider'), ['github', 'gitlab'], 'gitlab')
            ->willReturn('gitlab');
        $prompt->expects($this->once())
            ->method('ask')
            ->with(MessageRef::key('config.project_init.prompt_gitlab_instance_url'), null)
            ->willReturn(' https://gitlab.example ');
        $prompt->expects($this->exactly(2))
            ->method('askHidden')
            ->willReturnCallback(static fn (MessageRef $question): string => match ($question->key) {
                'config.project_init.prompt_github_token' => ' gh-token ',
                default => 'gl-token',
            });
        $recorder = new WorkflowRecorder();

        $patches = $this->createPrompts($prompt, $gitRepository)->hostingPatches([], $recorder);

        $this->assertSame([
            'gitProvider' => 'gitlab',
            'gitlabInstanceUrl' => 'https://gitlab.example',
            'githubToken' => 'gh-token',
            'gitlabToken' => 'gl-token',
        ], $patches);
        $this->assertCount(3, $recorder->getEntries());
    }

    public function testHostingPatchesKeepsValidProviderAndExistingTokens(): void
    {
        $gitRepository = $this->createStub(GitRepository::class);
        $gitRepository->method('parseGitUrl')->willReturn([]);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('choice');
        $prompt->expects($this->once())
            ->method('ask')
            ->with(MessageRef::key('config.project_init.prompt_gitlab_instance_url'), 'https://stored.example')
            ->willReturn('');
        $prompt->expects($this->exactly(2))->method('askHidden')->willReturn(' . ');

        $patches = $this->createPrompts($prompt, $gitRepository)->hostingPatches([
            'gitProvider' => 'github',
            'gitlabInstanceUrl' => 'https://stored.example',
            'githubToken' => 'gh',
            'gitlabToken' => 'gl',
        ], new WorkflowRecorder());

        $this->assertSame([], $patches);
    }

    public function testHostingPatchesDefaultsProviderToGithubAndSkipsBlankTokens(): void
    {
        $gitRepository = $this->createStub(GitRepository::class);
        $gitRepository->method('parseGitUrl')->willReturn(['provider' => 'bitbucket']);

        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('choice')
            ->with(MessageRef::key('config.project_init.prompt_git_provider'), ['github', 'gitlab'], 'github')
            ->willReturn('github');
        $prompt->method('ask')->willReturn(null);
        $prompt->expects($this->exactly(2))->method('askHidden')->willReturn(null);

        $patches = $this->createPrompts($prompt, $gitRepository)->hostingPatches(['gitProvider' => 'svn'], new WorkflowRecorder());

        $this->assertSame(['gitProvider' => 'github'], $patches);
    }
}
