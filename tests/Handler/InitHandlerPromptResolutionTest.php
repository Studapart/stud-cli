<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\InitPromptInputHelper;
use App\Service\Prompt\PromptInterface;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for config:init prompt resolution (skip, re-prompt, reject prompt-as-value).
 */
class InitHandlerPromptResolutionTest extends TestCase
{
    private TranslationService $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $translationsPath = __DIR__ . '/../../src/resources/translations';
        $this->translator = new TranslationService('en', $translationsPath);
    }

    /**
     * @param MockObject&PromptInterface $prompt
     */
    private function createInputHelper(PromptInterface $prompt): InitPromptInputHelper
    {
        return new InitPromptInputHelper($prompt);
    }

    public function testPromptRequiredVisibleRepromptsWhenSkippedWithNoStoredValue(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('ask')
            ->with('Q', null)
            ->willReturnOnConsecutiveCalls('', 'https://example.com/');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredVisible('Q', null, fn (string $s): string => rtrim($s, '/'));
        $this->assertSame('https://example.com', $result);
    }

    public function testPromptRequiredVisibleSkipsToStoredWhenEmptyInput(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with('Q', 'https://old.example.com')
            ->willReturn('');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredVisible('Q', 'https://old.example.com', fn (string $s): string => rtrim($s, '/'));
        $this->assertSame('https://old.example.com', $result);
    }

    public function testPromptRequiredVisibleRejectsValueEqualToQuestionThenAccepts(): void
    {
        $question = $this->translator->trans('config.init.jira.url_prompt');
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('ask')
            ->with($question, null)
            ->willReturnOnConsecutiveCalls($question, 'https://ok.example.com');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredVisible($question, null, fn (string $s): string => rtrim($s, '/'));
        $this->assertSame('https://ok.example.com', $result);
    }

    public function testPromptRequiredVisibleFallsBackToStoredWhenNewInputNormalizesToEmpty(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with('Q', 'https://keep.example.com')
            ->willReturn('/');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredVisible('Q', 'https://keep.example.com', fn (string $s): string => rtrim($s, '/'));
        $this->assertSame('https://keep.example.com', $result);
    }

    public function testPromptRequiredHiddenTokenRepromptsWhenSkippedWithNoStoredValue(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('askHidden')
            ->with('H')
            ->willReturnOnConsecutiveCalls(null, 'secret');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredHiddenToken('H', null);
        $this->assertSame('secret', $result);
    }

    public function testPromptRequiredHiddenTokenRejectsPromptTextThenAccepts(): void
    {
        $question = $this->translator->trans('config.init.jira.token_prompt');
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('askHidden')
            ->with($question)
            ->willReturnOnConsecutiveCalls($question, 'real-secret');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredHiddenToken($question, null);
        $this->assertSame('real-secret', $result);
    }

    public function testPromptRequiredHiddenTokenPreservesStoredOnSkip(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('askHidden')
            ->with('H')
            ->willReturn('');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredHiddenToken('H', 'stored-token');
        $this->assertSame('stored-token', $result);
    }

    public function testResolveWhenActiveReturnsStoredWhenInactive(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('ask');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->resolveWhenActive(
            false,
            false,
            [],
            'jiraUrl',
            '  https://stored.example.com/  ',
            'Q',
        );
        $this->assertSame('https://stored.example.com/', $result);
    }

    public function testResolveWhenActiveUsesAgentInputWhenActive(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('ask');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->resolveWhenActive(
            true,
            true,
            ['jiraUrl' => 'https://agent.example.com/'],
            'jiraUrl',
            null,
            'Q',
            false,
            fn (string $s): string => rtrim($s, '/'),
        );
        $this->assertSame('https://agent.example.com', $result);
    }

    public function testResolveWhenActiveUsesHiddenPromptWhenActiveInteractive(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('askHidden')
            ->with('H')
            ->willReturn('secret');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->resolveWhenActive(
            true,
            false,
            [],
            'token',
            null,
            'H',
            hidden: true,
        );
        $this->assertSame('secret', $result);
    }

    public function testResolveWhenActiveUsesVisiblePromptWhenActiveInteractive(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with('Q', null)
            ->willReturn('answer');
        $helper = $this->createInputHelper($prompt);
        $result = $helper->resolveWhenActive(
            true,
            false,
            [],
            'key',
            null,
            'Q',
        );
        $this->assertSame('answer', $result);
    }

    public function testPromptRequiredAgentStringFallsBackToStoredWhenInputEmpty(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredAgentString([], 'jiraUrl', 'https://stored.example.com', static fn (string $s): string => $s);
        $this->assertSame('https://stored.example.com', $result);
    }

    public function testPromptRequiredAgentStringRejectsKeyAsValueAndUsesStored(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredAgentString(['jiraUrl' => 'jiraUrl'], 'jiraUrl', 'stored', static fn (string $s): string => $s);
        $this->assertSame('stored', $result);
    }

    public function testPromptRequiredAgentStringReturnsEmptyWhenNoStoredValue(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredAgentString(['jiraUrl' => ''], 'jiraUrl', null, static fn (string $s): string => $s);
        $this->assertSame('', $result);
    }

    public function testPromptRequiredAgentStringReturnsEmptyWhenKeyEchoedWithNoStored(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $helper = $this->createInputHelper($prompt);
        $result = $helper->promptRequiredAgentString(['jiraUrl' => 'jiraUrl'], 'jiraUrl', null, static fn (string $s): string => $s);
        $this->assertSame('', $result);
    }
}
