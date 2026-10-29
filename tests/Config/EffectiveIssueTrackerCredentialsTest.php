<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\EffectiveIssueTrackerCredentials;
use App\Config\GlobalStudConfigKeys;
use PHPUnit\Framework\TestCase;

class EffectiveIssueTrackerCredentialsTest extends TestCase
{
    private EffectiveIssueTrackerCredentials $credentials;

    protected function setUp(): void
    {
        $this->credentials = new EffectiveIssueTrackerCredentials();
    }

    public function testOverlayReplacesEachCredentialIndependently(): void
    {
        $global = $this->global();
        $project = [
            GlobalStudConfigKeys::JIRA_URL => ' https://proj.example.com/ ',
            GlobalStudConfigKeys::JIRA_EMAIL => ' proj@example.com ',
            GlobalStudConfigKeys::LINEAR_API_KEY => ' project-lin ',
        ];

        $overlay = $this->credentials->overlay($global, $project);

        $this->assertSame('https://proj.example.com', $overlay[GlobalStudConfigKeys::JIRA_URL]);
        $this->assertSame('proj@example.com', $overlay[GlobalStudConfigKeys::JIRA_EMAIL]);
        $this->assertSame('token', $overlay[GlobalStudConfigKeys::JIRA_API_TOKEN]);
        $this->assertSame('project-lin', $overlay[GlobalStudConfigKeys::LINEAR_API_KEY]);
        $this->assertSame('https://wiki.example.com', $overlay['CONFLUENCE_URL']);
        $this->assertTrue($overlay['JIRA_TRANSITION_ENABLED']);
        $this->assertSame(['jira', 'linear'], $overlay['ISSUE_TRACKER_PROVIDERS']);
        $this->assertSame('gh', $overlay['GITHUB_TOKEN']);
        $this->assertSame($global, $this->global());
    }

    public function testBlankWhitespaceAndNonStringDoNotOverride(): void
    {
        $overlay = $this->credentials->overlay($this->global(), [
            GlobalStudConfigKeys::JIRA_URL => '   ',
            GlobalStudConfigKeys::JIRA_EMAIL => '',
            GlobalStudConfigKeys::JIRA_API_TOKEN => 12,
            GlobalStudConfigKeys::LINEAR_API_KEY => null,
        ]);

        $this->assertSame('https://global.example.com', $overlay[GlobalStudConfigKeys::JIRA_URL]);
        $this->assertSame('global@example.com', $overlay[GlobalStudConfigKeys::JIRA_EMAIL]);
        $this->assertSame('token', $overlay[GlobalStudConfigKeys::JIRA_API_TOKEN]);
        $this->assertSame('lin', $overlay[GlobalStudConfigKeys::LINEAR_API_KEY]);
    }

    public function testNullProjectAndMissingProjectLeaveGlobalUnchanged(): void
    {
        $global = $this->global();

        $this->assertSame($global, $this->credentials->overlay($global, null));
        $this->assertSame($global, $this->credentials->overlay($global, []));
    }

    public function testProjectSuppliesOnlyQualifiedCredentialKeys(): void
    {
        $project = [
            GlobalStudConfigKeys::JIRA_URL => 'https://proj.example.com/',
            GlobalStudConfigKeys::JIRA_EMAIL => '   ',
            'GITHUB_TOKEN' => 'proj-gh',
        ];

        $this->assertTrue($this->credentials->projectSupplies($project, GlobalStudConfigKeys::JIRA_URL));
        $this->assertFalse($this->credentials->projectSupplies($project, GlobalStudConfigKeys::JIRA_EMAIL));
        $this->assertFalse($this->credentials->projectSupplies($project, 'GITHUB_TOKEN'));
    }

    public function testNormalizeInputPatchesTrimsAndDropsBlanks(): void
    {
        $patches = $this->credentials->normalizeInputPatches([
            'jiraUrl' => ' https://proj.example.com/// ',
            'jiraEmail' => ' proj@example.com ',
            'jiraApiToken' => ' tok ',
            'linearApiKey' => '   ',
            'projectKey' => ' sci ',
        ]);

        $this->assertSame([
            'jiraUrl' => 'https://proj.example.com',
            'jiraEmail' => 'proj@example.com',
            'jiraApiToken' => 'tok',
            'projectKey' => ' sci ',
        ], $patches);
    }

    public function testNormalizeLeavesNonStringCredentialValuesUntouched(): void
    {
        $this->assertSame(['jiraUrl' => 1], $this->credentials->normalizeInputPatches(['jiraUrl' => 1]));
    }

    public function testSlashOnlyUrlIsDropped(): void
    {
        $this->assertSame([], $this->credentials->normalizeInputPatches(['jiraUrl' => '///']));
    }

    /**
     * @return array<string, mixed>
     */
    private function global(): array
    {
        return [
            GlobalStudConfigKeys::JIRA_URL => 'https://global.example.com',
            GlobalStudConfigKeys::JIRA_EMAIL => 'global@example.com',
            GlobalStudConfigKeys::JIRA_API_TOKEN => 'token',
            GlobalStudConfigKeys::LINEAR_API_KEY => 'lin',
            'CONFLUENCE_URL' => 'https://wiki.example.com',
            'JIRA_TRANSITION_ENABLED' => true,
            'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
            'GITHUB_TOKEN' => 'gh',
        ];
    }
}
