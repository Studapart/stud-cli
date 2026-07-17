<?php

declare(strict_types=1);

namespace App\Tests\Handler\GlobalInit;

use App\Contract\WorkflowEntryRecorder;
use App\Handler\GlobalInit\GitProviderTokensCollector;
use App\Handler\GlobalInit\GlobalInitPromptContext;
use App\Handler\GlobalInit\LinearApiKeyCollector;
use App\Handler\InitPromptInputHelper;
use App\Service\GitTokenPromptResolver;
use App\Service\GlobalConfigProviderResolver;
use App\Service\Prompt\PromptInterface;
use PHPUnit\Framework\TestCase;

class GlobalInitProviderCollectorsTest extends TestCase
{
    public function testLinearCollectorAnnouncesSectionWhenActiveInteractive(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('askHidden')
            ->willReturn('linear-key');
        $recorder = $this->createMock(WorkflowEntryRecorder::class);
        $recorder->expects($this->once())->method('addSection');
        $recorder->expects($this->once())->method('addText');

        $collector = new LinearApiKeyCollector(
            new GlobalConfigProviderResolver(),
            new InitPromptInputHelper($prompt),
        );

        $context = new GlobalInitPromptContext(
            [],
            [],
            false,
            $recorder,
            ['github'],
            ['linear'],
        );

        $result = $collector->collect($context);

        $this->assertSame(['LINEAR_API_KEY' => 'linear-key'], $result);
    }

    public function testGitTokensCollectorUsesAgentGithubTokenInput(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('askHidden');

        $collector = new GitProviderTokensCollector(
            new GlobalConfigProviderResolver(),
            new InitPromptInputHelper($prompt),
            new GitTokenPromptResolver(),
            $prompt,
        );

        $recorder = $this->createMock(WorkflowEntryRecorder::class);
        $context = new GlobalInitPromptContext(
            [],
            ['githubToken' => 'gh-agent-token'],
            true,
            $recorder,
            ['github'],
            ['jira'],
        );

        $result = $collector->collect($context);

        $this->assertSame('gh-agent-token', $result['GITHUB_TOKEN']);
        $this->assertNull($result['GITLAB_TOKEN']);
    }

    public function testGitTokensCollectorSkipsGithubInputWhenGithubNotSelected(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('askHidden')
            ->willReturn('gl-token');

        $collector = new GitProviderTokensCollector(
            new GlobalConfigProviderResolver(),
            new InitPromptInputHelper($prompt),
            new GitTokenPromptResolver(),
            $prompt,
        );

        $recorder = $this->createMock(WorkflowEntryRecorder::class);
        $context = new GlobalInitPromptContext(
            ['GITHUB_TOKEN' => 'keep-github'],
            [],
            false,
            $recorder,
            ['gitlab'],
            ['jira'],
        );

        $result = $collector->collect($context);

        $this->assertSame('keep-github', $result['GITHUB_TOKEN']);
        $this->assertSame('gl-token', $result['GITLAB_TOKEN']);
    }
}
