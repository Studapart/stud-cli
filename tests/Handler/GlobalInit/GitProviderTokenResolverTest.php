<?php

declare(strict_types=1);

namespace App\Tests\Handler\GlobalInit;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\InitPromptField;
use App\DTO\MessageRef;
use App\Handler\GlobalInit\GitProviderTokenResolver;
use App\Handler\GlobalInit\GlobalInitPromptContext;
use App\Handler\InitPromptInputHelper;
use App\Service\GitTokenPromptResolver;
use App\Service\Prompt\PromptInterface;
use PHPUnit\Framework\TestCase;

class GitProviderTokenResolverTest extends TestCase
{
    public function testInactiveProviderKeepsTrimmedStoredTokenWithoutPrompting(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('askHidden');

        $token = $this->resolver($prompt)->resolve(false, $this->context([], [], false), 'GITHUB_TOKEN', $this->field(' stored '));

        $this->assertSame('stored', $token);
    }

    public function testInactiveProviderIgnoresNonStringStoredValue(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('askHidden');

        $token = $this->resolver($prompt)->resolve(false, $this->context([], [], false), 'GITHUB_TOKEN', $this->field(123));

        $this->assertNull($token);
    }

    public function testActiveAgentProviderUsesAgentInput(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('askHidden');
        $context = $this->context([], ['githubToken' => 'agent-token'], true);

        $token = $this->resolver($prompt)->resolve(true, $context, 'GITHUB_TOKEN', $this->field(null));

        $this->assertSame('agent-token', $token);
    }

    public function testActiveAgentProviderWithoutStringInputFallsBackToExistingConfig(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('askHidden');
        $context = $this->context(['GITHUB_TOKEN' => 'existing'], ['githubToken' => ['not-a-string']], true);

        $token = $this->resolver($prompt)->resolve(true, $context, 'GITHUB_TOKEN', $this->field('existing'));

        $this->assertSame('existing', $token);
    }

    public function testActiveInteractiveProviderAsksHiddenPromptAndFallsBackToLegacyToken(): void
    {
        $field = $this->field(null);
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())->method('askHidden')->with($field->interactivePrompt)->willReturn('');
        $context = $this->context(['GIT_TOKEN' => 'legacy-token', 'GIT_PROVIDER' => 'github'], [], false);

        $token = $this->resolver($prompt)->resolve(true, $context, 'GITHUB_TOKEN', $field);

        $this->assertSame('legacy-token', $token);
    }

    private function resolver(PromptInterface $prompt): GitProviderTokenResolver
    {
        return new GitProviderTokenResolver(new InitPromptInputHelper($prompt), new GitTokenPromptResolver(), $prompt);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $agentInput
     */
    private function context(array $existing, array $agentInput, bool $isAgent): GlobalInitPromptContext
    {
        return new GlobalInitPromptContext(
            $existing,
            $agentInput,
            $isAgent,
            $this->createStub(WorkflowEntryRecorder::class),
            ['github'],
            ['jira'],
        );
    }

    private function field(mixed $existingStored): InitPromptField
    {
        return new InitPromptField('githubToken', $existingStored, MessageRef::key('config.init.git.github_token_prompt', [], 'Enter your GitHub PAT'));
    }
}
