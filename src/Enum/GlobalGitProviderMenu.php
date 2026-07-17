<?php

declare(strict_types=1);

namespace App\Enum;

use App\DTO\MessageRef;
use App\Service\MessageRenderer;

/**
 * Numbered global init menu for Git provider selection (0 / 1 / 2).
 */
enum GlobalGitProviderMenu: string
{
    case GithubOnly = 'github_only';
    case GitlabOnly = 'gitlab_only';
    case Both = 'both';

    /**
     * @return list<GlobalGitProviderMenu>
     */
    public static function orderedCases(): array
    {
        return [
            self::GithubOnly,
            self::GitlabOnly,
            self::Both,
        ];
    }

    public function choiceMessageKey(): string
    {
        return match ($this) {
            self::GithubOnly => 'config.init.git_provider.choice_github',
            self::GitlabOnly => 'config.init.git_provider.choice_gitlab',
            self::Both => 'config.init.git_provider.choice_both',
        };
    }

    /**
     * @return list<GitProvider>
     */
    public function toGitProviders(): array
    {
        return match ($this) {
            self::GithubOnly => [GitProvider::Github],
            self::GitlabOnly => [GitProvider::Gitlab],
            self::Both => [GitProvider::Github, GitProvider::Gitlab],
        };
    }

    /**
     * @return list<string>
     */
    public function toProviderValues(): array
    {
        return array_map(static fn (GitProvider $provider): string => $provider->value, $this->toGitProviders());
    }

    /**
     * @param list<string> $providerValues
     */
    public static function fromProviderValues(array $providerValues): self
    {
        $normalized = array_values(array_unique(array_map('strtolower', $providerValues)));
        sort($normalized);

        if ($normalized === [GitProvider::Github->value, GitProvider::Gitlab->value]) {
            return self::Both;
        }
        if ($normalized === [GitProvider::Gitlab->value]) {
            return self::GitlabOnly;
        }

        return self::GithubOnly;
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
