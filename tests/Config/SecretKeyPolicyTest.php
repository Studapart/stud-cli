<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\SecretKeyPolicy;
use PHPUnit\Framework\TestCase;

class SecretKeyPolicyTest extends TestCase
{
    public function testIsSecretKeyReturnsTrueForKnownKeys(): void
    {
        $this->assertTrue(SecretKeyPolicy::isSecretKey('JIRA_API_TOKEN'));
        $this->assertTrue(SecretKeyPolicy::isSecretKey('GITHUB_TOKEN'));
        $this->assertTrue(SecretKeyPolicy::isSecretKey('GITLAB_TOKEN'));
    }

    public function testIsSecretKeyReturnsTrueForFallbackPatterns(): void
    {
        $this->assertTrue(SecretKeyPolicy::isSecretKey('MY_CUSTOM_TOKEN'));
        $this->assertTrue(SecretKeyPolicy::isSecretKey('api_password'));
        $this->assertTrue(SecretKeyPolicy::isSecretKey('SECRET_KEY'));
    }

    public function testIsSecretKeyReturnsFalseForNonSecretKeys(): void
    {
        $this->assertFalse(SecretKeyPolicy::isSecretKey('LANGUAGE'));
        $this->assertFalse(SecretKeyPolicy::isSecretKey('JIRA_URL'));
        $this->assertFalse(SecretKeyPolicy::isSecretKey('projectKey'));
    }

    public function testRedactReplacesSecretKeysWithPlaceholder(): void
    {
        $config = [
            'LANGUAGE' => 'en',
            'JIRA_API_TOKEN' => 'secret123',
            'JIRA_URL' => 'https://jira.example.com',
        ];

        $result = SecretKeyPolicy::redact($config);

        $this->assertSame('en', $result['LANGUAGE']);
        $this->assertSame(SecretKeyPolicy::REDACTED_PLACEHOLDER, $result['JIRA_API_TOKEN']);
        $this->assertSame('https://jira.example.com', $result['JIRA_URL']);
    }

    public function testRedactRedactsUrlWithQueryString(): void
    {
        $config = [
            'JIRA_URL' => 'https://jira.example.com?token=abc',
        ];

        $result = SecretKeyPolicy::redact($config);

        $this->assertSame(SecretKeyPolicy::REDACTED_PLACEHOLDER, $result['JIRA_URL']);
    }

    public function testRedactPreservesNonSecretValues(): void
    {
        $config = [
            'LANGUAGE' => 'fr',
            'JIRA_TRANSITION_ENABLED' => true,
        ];

        $result = SecretKeyPolicy::redact($config);

        $this->assertSame('fr', $result['LANGUAGE']);
        $this->assertTrue($result['JIRA_TRANSITION_ENABLED']);
    }
}
