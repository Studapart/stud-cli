<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\AgentInputAliases;
use PHPUnit\Framework\TestCase;

class AgentInputAliasesTest extends TestCase
{
    public function testNormalizeMapsLegacyIssueTrackerProviderKeys(): void
    {
        $input = [
            'workItemProviders' => ['jira'],
            'workItemProvider' => 'linear',
        ];

        $normalized = AgentInputAliases::normalize($input);

        $this->assertSame(['jira'], $normalized['issueTrackerProviders']);
        $this->assertSame('linear', $normalized['issueTrackerProvider']);
        $this->assertArrayNotHasKey('workItemProviders', $normalized);
        $this->assertArrayNotHasKey('workItemProvider', $normalized);
    }

    public function testNormalizePrefersCanonicalKeysOverLegacy(): void
    {
        $input = [
            'workItemProviders' => ['jira'],
            'issueTrackerProviders' => ['linear'],
            'workItemProvider' => 'jira',
            'issueTrackerProvider' => 'auto',
        ];

        $normalized = AgentInputAliases::normalize($input);

        $this->assertSame(['linear'], $normalized['issueTrackerProviders']);
        $this->assertSame('auto', $normalized['issueTrackerProvider']);
        $this->assertArrayNotHasKey('workItemProviders', $normalized);
        $this->assertArrayNotHasKey('workItemProvider', $normalized);
    }

    public function testNormalizeRemovesLegacyKeysAfterMapping(): void
    {
        $input = ['workItemProvider' => 'linear'];

        $normalized = AgentInputAliases::normalize($input);

        $this->assertSame('linear', $normalized['issueTrackerProvider']);
        $this->assertArrayNotHasKey('workItemProvider', $normalized);
    }
}
