<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\WorkflowChannel;
use App\Service\WorkflowChannelColorizer;
use PHPUnit\Framework\TestCase;

class WorkflowChannelColorizerTest extends TestCase
{
    public function testDefaultChannelReturnsMessageUnchanged(): void
    {
        $colorizer = new WorkflowChannelColorizer(['jira_message' => 'cyan']);

        $this->assertSame('plain', $colorizer->colorize(WorkflowChannel::Default, 'plain'));
        $this->assertSame(['a', 'b'], $colorizer->colorize(WorkflowChannel::Default, ['a', 'b']));
    }

    public function testJiraChannelUsesConfiguredColor(): void
    {
        $colorizer = new WorkflowChannelColorizer(['jira_message' => 'cyan']);

        $this->assertSame('<fg=cyan>Issue</>', $colorizer->colorize(WorkflowChannel::Jira, 'Issue'));
    }

    public function testGitChannelUsesConfiguredColorForEachLine(): void
    {
        $colorizer = new WorkflowChannelColorizer(['git_message' => 'green']);

        $this->assertSame(
            ['<fg=green>one</>', '<fg=green>two</>'],
            $colorizer->colorize(WorkflowChannel::Git, ['one', 'two'])
        );
    }

    public function testChannelsFallBackToDefaultColors(): void
    {
        $colorizer = new WorkflowChannelColorizer([]);

        $this->assertSame('<fg=blue>Issue</>', $colorizer->colorize(WorkflowChannel::Jira, 'Issue'));
        $this->assertSame('<fg=yellow>Branch</>', $colorizer->colorize(WorkflowChannel::Git, 'Branch'));
    }
}
