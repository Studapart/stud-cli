<?php

declare(strict_types=1);

namespace App\Enum;

use App\DTO\MessageRef;
use App\Service\MessageRenderer;

/**
 * Numbered global init menu for issue-tracker provider selection (0 / 1 / 2).
 */
enum GlobalIssueTrackerProviderMenu: string
{
    case JiraOnly = 'jira_only';
    case LinearOnly = 'linear_only';
    case Both = 'both';

    /**
     * @return list<GlobalIssueTrackerProviderMenu>
     */
    public static function orderedCases(): array
    {
        return [
            self::JiraOnly,
            self::LinearOnly,
            self::Both,
        ];
    }

    public function choiceMessageKey(): string
    {
        return match ($this) {
            self::JiraOnly => 'config.init.issue_tracker_provider.choice_jira',
            self::LinearOnly => 'config.init.issue_tracker_provider.choice_linear',
            self::Both => 'config.init.issue_tracker_provider.choice_both',
        };
    }

    /**
     * @return list<IssueTrackerProvider>
     */
    public function toIssueTrackerProviders(): array
    {
        return match ($this) {
            self::JiraOnly => [IssueTrackerProvider::Jira],
            self::LinearOnly => [IssueTrackerProvider::Linear],
            self::Both => [IssueTrackerProvider::Jira, IssueTrackerProvider::Linear],
        };
    }

    /**
     * @return list<string>
     */
    public function toProviderValues(): array
    {
        return array_map(static fn (IssueTrackerProvider $provider): string => $provider->value, $this->toIssueTrackerProviders());
    }

    /**
     * @param list<string> $providerValues
     */
    public static function fromProviderValues(array $providerValues): self
    {
        $normalized = array_values(array_unique(array_map('strtolower', $providerValues)));
        sort($normalized);

        if ($normalized === [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value]) {
            return self::Both;
        }
        if ($normalized === [IssueTrackerProvider::Linear->value]) {
            return self::LinearOnly;
        }

        return self::JiraOnly;
    }

    public static function fromRenderedChoice(string $choice, MessageRenderer $renderer): self
    {
        foreach (self::orderedCases() as $case) {
            if ($renderer->render(MessageRef::key($case->choiceMessageKey())) === $choice) {
                return $case;
            }
        }

        return self::Both;
    }
}
