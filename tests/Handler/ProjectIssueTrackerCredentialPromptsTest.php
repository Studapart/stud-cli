<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Config\GlobalStudConfigKeys;
use App\DTO\MessageRef;
use App\Handler\ProjectIssueTrackerCredentialPrompts;
use App\Service\Prompt\PromptInterface;
use PHPUnit\Framework\TestCase;

class ProjectIssueTrackerCredentialPromptsTest extends TestCase
{
    public function testJiraPromptsStoreTrimmedValuesAndKeepDot(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->exactly(2))
            ->method('ask')
            ->willReturnCallback(function (MessageRef $question, ?string $default): string {
                if ($question->key === 'config.project_init.prompt_jira_url') {
                    $this->assertSame('https://stored.example', $default);

                    return ' https://proj.example/ ';
                }

                $this->assertSame('config.project_init.prompt_jira_email', $question->key);
                $this->assertNull($default);

                return ' person@example.com ';
            });
        $prompt->expects($this->once())
            ->method('askHidden')
            ->willReturn(' . ');

        $patches = (new ProjectIssueTrackerCredentialPrompts($prompt))->jiraPatches([
            GlobalStudConfigKeys::JIRA_URL => ' https://stored.example ',
        ]);

        $this->assertSame([
            'jiraUrl' => 'https://proj.example',
            'jiraEmail' => 'person@example.com',
            'jiraApiToken' => '.',
        ], $patches);
    }

    public function testBlankAnswersDoNotWrite(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->method('ask')->willReturnOnConsecutiveCalls(null, '   ');
        $prompt->method('askHidden')->willReturn(null);

        $prompts = new ProjectIssueTrackerCredentialPrompts($prompt);

        $this->assertSame([], $prompts->jiraPatches([]));
        $this->assertSame([], $prompts->linearPatches());
    }

    public function testLinearPromptStoresTrimmedKey(): void
    {
        $prompt = $this->createMock(PromptInterface::class);
        $prompt->expects($this->once())
            ->method('askHidden')
            ->with($this->callback(static fn (MessageRef $question): bool => $question->key === 'config.project_init.prompt_linear_api_key'))
            ->willReturn(' lin-key ');

        $this->assertSame(
            ['linearApiKey' => 'lin-key'],
            (new ProjectIssueTrackerCredentialPrompts($prompt))->linearPatches(),
        );
    }
}
