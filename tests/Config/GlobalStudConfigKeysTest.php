<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\GlobalStudConfigKeys;
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
