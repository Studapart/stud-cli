<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\GlobalStudConfigKeys;
use App\Enum\IssueTrackerProvider;
use PHPUnit\Framework\TestCase;

class GlobalStudConfigKeysTest extends TestCase
{
    public function testRequiredJiraCredentialKeys(): void
    {
        $this->assertSame(
            [
                GlobalStudConfigKeys::JIRA_URL,
                GlobalStudConfigKeys::JIRA_EMAIL,
                GlobalStudConfigKeys::JIRA_API_TOKEN,
            ],
            GlobalStudConfigKeys::requiredJiraCredentialKeys(),
        );
    }

    public function testHasJiraCredentialsRequiresAllKeys(): void
    {
        $this->assertFalse(GlobalStudConfigKeys::hasJiraCredentials([]));
        $this->assertFalse(GlobalStudConfigKeys::hasJiraCredentials([
            GlobalStudConfigKeys::JIRA_URL => 'https://example.atlassian.net',
        ]));
        $this->assertTrue(GlobalStudConfigKeys::hasJiraCredentials([
            GlobalStudConfigKeys::JIRA_URL => 'https://example.atlassian.net',
            GlobalStudConfigKeys::JIRA_EMAIL => 'user@example.com',
            GlobalStudConfigKeys::JIRA_API_TOKEN => 'token',
        ]));
    }

    public function testHasLinearApiKey(): void
    {
        $this->assertFalse(GlobalStudConfigKeys::hasLinearApiKey([]));
        $this->assertTrue(GlobalStudConfigKeys::hasLinearApiKey([
            GlobalStudConfigKeys::LINEAR_API_KEY => 'lin_api_123',
        ]));
    }

    public function testHasCredentialsFor(): void
    {
        $jiraConfig = [
            GlobalStudConfigKeys::JIRA_URL => 'https://example.atlassian.net',
            GlobalStudConfigKeys::JIRA_EMAIL => 'user@example.com',
            GlobalStudConfigKeys::JIRA_API_TOKEN => 'token',
        ];

        $this->assertTrue(GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Jira, $jiraConfig));
        $this->assertFalse(GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Jira, []));
        $this->assertTrue(GlobalStudConfigKeys::hasCredentialsFor(
            IssueTrackerProvider::Linear,
            [GlobalStudConfigKeys::LINEAR_API_KEY => 'lin_api_123'],
        ));
        $this->assertFalse(GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Auto, $jiraConfig));
    }

    public function testHasNonEmptyStringValue(): void
    {
        $this->assertFalse(GlobalStudConfigKeys::hasNonEmptyStringValue([], GlobalStudConfigKeys::JIRA_URL));
        $this->assertFalse(GlobalStudConfigKeys::hasNonEmptyStringValue([
            GlobalStudConfigKeys::JIRA_URL => '   ',
        ], GlobalStudConfigKeys::JIRA_URL));
        $this->assertTrue(GlobalStudConfigKeys::hasNonEmptyStringValue([
            GlobalStudConfigKeys::JIRA_URL => 'https://example.atlassian.net',
        ], GlobalStudConfigKeys::JIRA_URL));
    }
}
