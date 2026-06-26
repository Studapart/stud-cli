<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\WorkItemProviderException;
use PHPUnit\Framework\TestCase;

class WorkItemProviderExceptionTest extends TestCase
{
    public function testMissingLinearApiKeyUsesMessageRef(): void
    {
        $exception = WorkItemProviderException::missingLinearApiKey();

        $this->assertSame('work_item_provider.missing_linear_api_key', $exception->messageRef->key);
    }

    public function testMissingJiraConfigurationUsesMessageRef(): void
    {
        $exception = WorkItemProviderException::missingJiraConfiguration();

        $this->assertSame('work_item_provider.missing_jira_configuration', $exception->messageRef->key);
    }

    public function testNotConfiguredUsesMessageRef(): void
    {
        $exception = WorkItemProviderException::notConfigured();

        $this->assertSame('work_item_provider.not_configured', $exception->messageRef->key);
    }
}
