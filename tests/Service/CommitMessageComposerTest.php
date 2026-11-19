<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\DTO\WorkItem;
use App\Service\CommitMessageComposer;
use App\Service\Prompt\PromptInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommitMessageComposerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function issueTypeProvider(): iterable
    {
        yield 'bug' => ['Bug', 'fix'];
        yield 'story' => ['story', 'feat'];
        yield 'epic' => ['Epic', 'feat'];
        yield 'task' => ['task', 'chore'];
        yield 'sub-task' => ['Sub-task', 'chore'];
        yield 'unknown' => ['unknown', 'feat'];
    }

    #[DataProvider('issueTypeProvider')]
    public function testQuietComposeMapsIssueTypeToCommitType(string $issueType, string $expectedType): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->never())->method('ask');

        $message = (new CommitMessageComposer($prompt))->compose($this->workItem($issueType, []), 'SCI-1', true);

        $this->assertSame("{$expectedType}: Add things [SCI-1]", $message);
    }

    public function testQuietComposeUsesFirstComponentAsScope(): void
    {
        $prompt = $this->createStub(PromptInterface::class);

        $message = (new CommitMessageComposer($prompt))->compose($this->workItem('bug', ['api', 'web']), 'SCI-1', true);

        $this->assertSame('fix(api): Add things [SCI-1]', $message);
    }

    public function testInteractiveComposeUsesPromptAnswers(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(3))
            ->method('ask')
            ->willReturnCallback(fn (MessageRef $question, ?string $default): string => match ($question->key) {
                'commit.type_prompt' => 'refactor',
                'commit.scope_auto' => 'core',
                'commit.summary_prompt' => 'Tidy up',
                default => throw new \RuntimeException('Unexpected question: ' . $question->key),
            });

        $message = (new CommitMessageComposer($prompt))->compose($this->workItem('story', ['api']), 'SCI-2', false);

        $this->assertSame('refactor(core): Tidy up [SCI-2]', $message);
    }

    public function testInteractiveComposeFallsBackToDetectedValuesWhenAnswersAreNull(): void
    {
        $asked = [];
        $prompt = $this->createStub(PromptInterface::class);
        $prompt->method('ask')->willReturnCallback(function (MessageRef $question) use (&$asked): ?string {
            $asked[] = $question->key;

            return null;
        });

        $message = (new CommitMessageComposer($prompt))->compose($this->workItem('task', []), 'SCI-3', false);

        $this->assertSame('chore: Add things [SCI-3]', $message);
        $this->assertSame(['commit.type_prompt', 'commit.scope_prompt', 'commit.summary_prompt'], $asked);
    }

    /**
     * @param list<string> $components
     */
    private function workItem(string $issueType, array $components): WorkItem
    {
        return new WorkItem(
            id: '1',
            key: 'SCI-1',
            title: 'Add things',
            status: 'To Do',
            assignee: 'Someone',
            description: '',
            labels: [],
            issueType: $issueType,
            components: $components,
        );
    }
}
