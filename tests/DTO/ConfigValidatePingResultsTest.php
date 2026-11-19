<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\ConfigValidatePingResults;
use PHPUnit\Framework\TestCase;

class ConfigValidatePingResultsTest extends TestCase
{
    public function testCheckedIntegrationsKeepsCheckedPingsAndKeysGitByProvider(): void
    {
        $jira = ConfigValidateIntegrationPing::ok();
        $git = ConfigValidateIntegrationPing::fail('down');
        $linear = ConfigValidateIntegrationPing::ok();
        $pings = new ConfigValidatePingResults($jira, $git, $linear);

        $this->assertSame(['jira' => $jira, 'linear' => $linear, 'gitlab' => $git], $pings->checkedIntegrations('gitlab'));
    }

    public function testCheckedIntegrationsOmitsSkippedPingsAndGitWithoutProvider(): void
    {
        $pings = new ConfigValidatePingResults(ConfigValidateIntegrationPing::skipped(), ConfigValidateIntegrationPing::ok());

        $this->assertSame([], $pings->checkedIntegrations(null));
    }

    public function testHasFailureIsFalseWhenNoPingFailed(): void
    {
        $pings = new ConfigValidatePingResults(ConfigValidateIntegrationPing::ok(), ConfigValidateIntegrationPing::skipped());

        $this->assertFalse($pings->hasFailure());
    }

    public function testHasFailureIsTrueWhenAnyPingFailed(): void
    {
        $ok = ConfigValidateIntegrationPing::ok();
        $fail = ConfigValidateIntegrationPing::fail('down');

        $this->assertTrue((new ConfigValidatePingResults($fail, $ok, $ok))->hasFailure());
        $this->assertTrue((new ConfigValidatePingResults($ok, $fail, $ok))->hasFailure());
        $this->assertTrue((new ConfigValidatePingResults($ok, $ok, $fail))->hasFailure());
    }
}
