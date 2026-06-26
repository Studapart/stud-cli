<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\IssueTrackerException;
use PHPUnit\Framework\TestCase;

class IssueTrackerExceptionTest extends TestCase
{
    public function testMissingLinearApiKeyUsesMessageRef(): void
    {
        $exception = IssueTrackerException::missingLinearApiKey();

        $this->assertSame('work_item_provider.missing_linear_api_key', $exception->messageRef->key);
    }

    public function testMissingJiraConfigurationUsesMessageRef(): void
    {
        $exception = IssueTrackerException::missingJiraConfiguration();

        $this->assertSame('work_item_provider.missing_jira_configuration', $exception->messageRef->key);
    }

    public function testNotConfiguredUsesMessageRef(): void
    {
        $exception = IssueTrackerException::notConfigured();

        $this->assertSame('work_item_provider.not_configured', $exception->messageRef->key);
    }
}
