<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\WorkflowChannel;

/**
 * Wraps workflow output in the console color configured for its channel (Jira, Git).
 */
class WorkflowChannelColorizer
{
    /**
     * @param array<string, string> $colors Color configuration (jira_message, git_message)
     */
    public function __construct(
        private readonly array $colors,
    ) {
    }

    /**
     * @param string|array<string> $message
     * @return string|array<string>
     */
    public function colorize(WorkflowChannel $channel, string|array $message): string|array
    {
        $color = match ($channel) {
            WorkflowChannel::Jira => $this->colors['jira_message'] ?? 'blue',
            WorkflowChannel::Git => $this->colors['git_message'] ?? 'yellow',
            WorkflowChannel::Default => null,
        };
        if ($color === null) {
            return $message;
        }
        if (is_array($message)) {
            return array_map(fn (string $line): string => "<fg={$color}>{$line}</>", $message);
        }

        return "<fg={$color}>{$message}</>";
    }
}
