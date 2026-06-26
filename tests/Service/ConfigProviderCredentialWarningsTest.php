<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Service\ConfigProviderCredentialWarnings;
use PHPUnit\Framework\TestCase;

class ConfigProviderCredentialWarningsTest extends TestCase
{
    private ConfigProviderCredentialWarnings $warnings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warnings = new ConfigProviderCredentialWarnings();
    }

    public function testWarnsWhenGitlabListedWithoutToken(): void
    {
        $messages = $this->warnings->collect([
            'GIT_PROVIDERS' => ['github', 'gitlab'],
            'GITHUB_TOKEN' => 'gh-token',
            'WORK_ITEM_PROVIDERS' => ['jira'],
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
        ]);

        $keys = array_map(
            static fn ($message) => $message->message instanceof MessageRef ? $message->message->key : null,
            $messages,
        );
        $this->assertContains('config.validate.warn_gitlab_token_missing', $keys);
    }

    public function testNoGitWarningWhenGithubTokenPresent(): void
    {
        $messages = $this->warnings->collect([
            'GIT_PROVIDERS' => ['github'],
            'GITHUB_TOKEN' => 'gh-token',
            'WORK_ITEM_PROVIDERS' => ['jira'],
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
        ]);

        $keys = array_map(
            static fn ($message) => $message->message instanceof MessageRef ? $message->message->key : null,
            $messages,
        );
        $this->assertNotContains('config.validate.warn_github_token_missing', $keys);
        $this->assertNotContains('config.validate.warn_gitlab_token_missing', $keys);
    }

    public function testWarnsForLinearWithoutApiKey(): void
    {
        $messages = $this->warnings->collect([
            'WORK_ITEM_PROVIDERS' => ['jira', 'linear'],
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
        ]);

        $this->assertCount(1, $messages);
        $this->assertSame(
            'config.validate.warn_linear_api_key_missing',
            $messages[0]->message instanceof MessageRef ? $messages[0]->message->key : null,
        );
    }
}
