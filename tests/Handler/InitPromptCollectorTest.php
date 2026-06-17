<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\WorkflowRecorder;
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
        $method = new \ReflectionMethod($this->collector, 'resolveWorkItemProviders');

        $result = $method->invoke(
            $this->collector,
            [],
            ['workItemProviders' => ['jira', 'linear', false]],
            true,
            $recorder,
        );

        $this->assertSame(['jira', 'linear'], $result);
    }
}
