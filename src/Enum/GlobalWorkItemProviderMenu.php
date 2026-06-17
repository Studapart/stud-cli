<?php

declare(strict_types=1);

namespace App\Enum;

use App\DTO\MessageRef;
use App\Service\MessageRenderer;

/**
 * Numbered global init menu for work-item provider selection (0 / 1 / 2).
 */
enum GlobalWorkItemProviderMenu: string
{
    case JiraOnly = 'jira_only';
    case LinearOnly = 'linear_only';
    case Both = 'both';

    /**
     * @return list<GlobalWorkItemProviderMenu>
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
            self::JiraOnly => 'config.init.work_item_provider.choice_jira',
            self::LinearOnly => 'config.init.work_item_provider.choice_linear',
            self::Both => 'config.init.work_item_provider.choice_both',
        };
    }

    /**
     * @return list<WorkItemProvider>
     */
    public function toWorkItemProviders(): array
    {
        return match ($this) {
            self::JiraOnly => [WorkItemProvider::Jira],
            self::LinearOnly => [WorkItemProvider::Linear],
            self::Both => [WorkItemProvider::Jira, WorkItemProvider::Linear],
        };
    }

    /**
     * @return list<string>
     */
    public function toProviderValues(): array
    {
        return array_map(static fn (WorkItemProvider $provider): string => $provider->value, $this->toWorkItemProviders());
    }

    /**
     * @param list<string> $providerValues
     */
    public static function fromProviderValues(array $providerValues): self
    {
        $normalized = array_values(array_unique(array_map('strtolower', $providerValues)));
        sort($normalized);

        if ($normalized === [WorkItemProvider::Jira->value, WorkItemProvider::Linear->value]) {
            return self::Both;
        }
        if ($normalized === [WorkItemProvider::Linear->value]) {
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
