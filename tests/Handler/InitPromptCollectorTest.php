<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Config\GlobalStudConfigKeys;
use App\DTO\WorkflowRecorder;
use App\Handler\GlobalInit\GitProviderTokensCollector;
use App\Handler\GlobalInit\JiraCredentialsCollector;
use App\Handler\GlobalInit\LinearApiKeyCollector;
use App\Handler\InitPromptCollector;
use App\Service\GitTokenPromptResolver;
use App\Service\GlobalConfigProviderResolver;
use App\Service\MessageRenderer;
use App\Service\Prompt\PromptInterface;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class InitPromptCollectorTest extends TestCase
{
    private InitPromptCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $translationService = new TranslationService('en', $translationsPath);
        $prompt = $this->createMock(PromptInterface::class);
        $this->collector = new InitPromptCollector(
            $prompt,
            new GitTokenPromptResolver(),
            new MessageRenderer($translationService),
            new GlobalConfigProviderResolver(),
        );
    }

    public function testResolveGitProvidersUsesAgentInputWhenProvided(): void
    {
        $recorder = new WorkflowRecorder();
        $method = new \ReflectionMethod($this->collector, 'resolveGitProviders');

        $result = $method->invoke(
            $this->collector,
            [],
            ['gitProviders' => ['github', 'gitlab', 99]],
            true,
            $recorder,
        );

        $this->assertSame(['github', 'gitlab'], $result);
    }

    public function testResolveWorkItemProvidersUsesAgentInputWhenProvided(): void
    {
        $recorder = new WorkflowRecorder();
        $method = new \ReflectionMethod($this->collector, 'resolveIssueTrackerProviders');

        $result = $method->invoke(
            $this->collector,
            [],
            ['workItemProviders' => ['jira', 'linear', false]],
            true,
            $recorder,
        );

        $this->assertSame(['jira', 'linear'], $result);
    }

    public function testBuildGlobalConfigNormalizesLegacyAgentIssueTrackerProviders(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('choice')->willReturn('English (en)');

        $jiraCollector = $this->createMock(JiraCredentialsCollector::class);
        $jiraCollector->method('collect')->willReturn([]);
        $jiraCollector->method('collectTransitionEnabled')->willReturn(false);

        $linearCollector = $this->createMock(LinearApiKeyCollector::class);
        $linearCollector->method('collect')->willReturn([]);

        $gitTokensCollector = $this->createMock(GitProviderTokensCollector::class);
        $gitTokensCollector->method('collect')->willReturn([]);

        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $collector = new InitPromptCollector(
            $prompt,
            new GitTokenPromptResolver(),
            new MessageRenderer(new TranslationService('en', $translationsPath)),
            new GlobalConfigProviderResolver(),
            null,
            $jiraCollector,
            $linearCollector,
            $gitTokensCollector,
        );

        $recorder = new WorkflowRecorder();
        $result = $collector->buildGlobalConfig(
            [],
            [
                'gitProviders' => ['github'],
                'workItemProviders' => ['jira', 'linear'],
            ],
            true,
            $recorder,
        );

        $this->assertSame('en', $result['LANGUAGE']);
        $this->assertSame(['github'], $result['GIT_PROVIDERS']);
        $this->assertSame(['jira', 'linear'], $result[GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS]);
    }
}
