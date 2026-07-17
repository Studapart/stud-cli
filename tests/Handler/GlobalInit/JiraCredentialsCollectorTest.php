<?php

declare(strict_types=1);

namespace App\Tests\Handler\GlobalInit;

use App\Contract\WorkflowEntryRecorder;
use App\Handler\GlobalInit\GlobalInitPromptContext;
use App\Handler\GlobalInit\JiraCredentialsCollector;
use App\Handler\InitPromptInputHelper;
use App\Service\GlobalConfigProviderResolver;
use App\Service\Prompt\PromptInterface;
use PHPUnit\Framework\TestCase;

class JiraCredentialsCollectorTest extends TestCase
{
    public function testCollectPreservesStoredValuesWhenJiraInactive(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('ask');
        $recorder = $this->createMock(WorkflowEntryRecorder::class);
        $recorder->expects($this->never())->method('addSection');

        $collector = new JiraCredentialsCollector(
            new GlobalConfigProviderResolver(),
            new InitPromptInputHelper($prompt),
            $prompt,
        );

        $context = new GlobalInitPromptContext(
            [
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'dev@example.com',
                'JIRA_API_TOKEN' => 'token',
                'JIRA_TRANSITION_ENABLED' => true,
            ],
            [],
            false,
            $recorder,
            ['github'],
            ['linear'],
        );

        $result = $collector->collect($context);

        $this->assertSame([
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'dev@example.com',
            'JIRA_API_TOKEN' => 'token',
        ], $result);
        $this->assertTrue($collector->collectTransitionEnabled($context));
    }

    public function testCollectTransitionEnabledUsesAgentInputWhenJiraActive(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('confirm');
        $recorder = $this->createMock(WorkflowEntryRecorder::class);

        $collector = new JiraCredentialsCollector(
            new GlobalConfigProviderResolver(),
            new InitPromptInputHelper($prompt),
            $prompt,
        );

        $context = new GlobalInitPromptContext(
            ['JIRA_TRANSITION_ENABLED' => false],
            ['jiraTransitionEnabled' => true],
            true,
            $recorder,
            ['github'],
            ['jira'],
        );

        $this->assertTrue($collector->collectTransitionEnabled($context));
    }
}
