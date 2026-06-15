<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\InitHandler;
use App\Service\FileSystem;
use App\Service\GitTokenPromptResolver;
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
    private function createExposingHandler(PromptInterface $prompt): InitHandler
    {
        $fileSystem = $this->createMock(FileSystem::class);

        return new class ($fileSystem, '/tmp/stud-test-config.yml', $this->translator, $prompt, new GitTokenPromptResolver()) extends InitHandler {
            public function exposePromptRequiredVisible(string $question, ?string $existing, callable $normalize): string
            {
                return $this->promptRequiredVisible($question, $existing, $normalize);
            }

            public function exposePromptRequiredJiraApiToken(string $question, ?string $existing): string
            {
                return $this->promptRequiredJiraApiToken($question, $existing);
            }
        };
    }

    public function testPromptRequiredVisibleRepromptsWhenSkippedWithNoStoredValue(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('ask')
            ->with('Q', null)
            ->willReturnOnConsecutiveCalls('', 'https://example.com/');
        $handler = $this->createExposingHandler($prompt);
        $result = $handler->exposePromptRequiredVisible('Q', null, fn (string $s): string => rtrim($s, '/'));
        $this->assertSame('https://example.com', $result);
    }

    public function testPromptRequiredVisibleSkipsToStoredWhenEmptyInput(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with('Q', 'https://old.example.com')
            ->willReturn('');
        $handler = $this->createExposingHandler($prompt);
        $result = $handler->exposePromptRequiredVisible('Q', 'https://old.example.com', fn (string $s): string => rtrim($s, '/'));
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
        $handler = $this->createExposingHandler($prompt);
        $result = $handler->exposePromptRequiredVisible($question, null, fn (string $s): string => rtrim($s, '/'));
        $this->assertSame('https://ok.example.com', $result);
    }

    public function testPromptRequiredVisibleFallsBackToStoredWhenNewInputNormalizesToEmpty(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('ask')
            ->with('Q', 'https://keep.example.com')
            ->willReturn('/');
        $handler = $this->createExposingHandler($prompt);
        $result = $handler->exposePromptRequiredVisible('Q', 'https://keep.example.com', fn (string $s): string => rtrim($s, '/'));
        $this->assertSame('https://keep.example.com', $result);
    }

    public function testPromptRequiredJiraApiTokenRepromptsWhenSkippedWithNoStoredValue(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('askHidden')
            ->with('H')
            ->willReturnOnConsecutiveCalls(null, 'secret');
        $handler = $this->createExposingHandler($prompt);
        $result = $handler->exposePromptRequiredJiraApiToken('H', null);
        $this->assertSame('secret', $result);
    }

    public function testPromptRequiredJiraApiTokenRejectsPromptTextThenAccepts(): void
    {
        $question = $this->translator->trans('config.init.jira.token_prompt');
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('askHidden')
            ->with($question)
            ->willReturnOnConsecutiveCalls($question, 'real-secret');
        $handler = $this->createExposingHandler($prompt);
        $result = $handler->exposePromptRequiredJiraApiToken($question, null);
        $this->assertSame('real-secret', $result);
    }

    public function testPromptRequiredJiraApiTokenPreservesStoredOnSkip(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('askHidden')
            ->with('H')
            ->willReturn('');
        $handler = $this->createExposingHandler($prompt);
        $result = $handler->exposePromptRequiredJiraApiToken('H', 'stored-token');
        $this->assertSame('stored-token', $result);
    }
}
