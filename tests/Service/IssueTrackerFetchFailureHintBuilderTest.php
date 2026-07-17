<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Service\IssueTrackerFetchFailureHintBuilder;
use PHPUnit\Framework\TestCase;

class IssueTrackerFetchFailureHintBuilderTest extends TestCase
{
    private IssueTrackerFetchFailureHintBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new IssueTrackerFetchFailureHintBuilder();
    }

    public function testNoHintWhenCliOverrideSet(): void
    {
        $hint = $this->builder->build(
            'SCIL-195',
            IssueTrackerProvider::Jira->value,
            'jira',
            $this->dualGlobal(),
            $this->pinnedJiraProject(),
        );

        $this->assertNull($hint);
    }

    public function testNoHintWhenProjectIsAuto(): void
    {
        $hint = $this->builder->build(
            'SCIL-195',
            IssueTrackerProvider::Linear->value,
            null,
            $this->dualGlobal(),
            [
                'issueTrackerProvider' => IssueTrackerProvider::Auto->value,
                'projectKey' => 'SCI',
                'linearTeamKey' => 'SCIL',
            ],
        );

        $this->assertNull($hint);
    }

    public function testHintWhenPinnedAndPrefixMatchesOther(): void
    {
        $hint = $this->builder->build(
            'SCIL-195',
            IssueTrackerProvider::Jira->value,
            null,
            $this->dualGlobal(),
            $this->pinnedJiraProject(),
        );

        $this->assertInstanceOf(MessageRef::class, $hint);
        $this->assertSame('issue_tracker_provider.fetch_failed_prefix_matches_other', $hint->key);
        $this->assertSame('SCIL-195', $hint->parameters['%key%']);
        $this->assertSame('jira', $hint->parameters['%attempted%']);
        $this->assertSame('SCIL', $hint->parameters['%prefix%']);
        $this->assertSame('linear', $hint->parameters['%alternate%']);
    }

    public function testNoHintWhenAlternateMatchesButCredentialsMissing(): void
    {
        $hint = $this->builder->build(
            'SCIL-195',
            IssueTrackerProvider::Jira->value,
            null,
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
            ],
            $this->pinnedJiraProject(),
        );

        $this->assertNull($hint);
    }

    public function testSuggestOverrideWhenPinnedAndPrefixMatchesNeither(): void
    {
        $hint = $this->builder->build(
            'ENG-1',
            IssueTrackerProvider::Jira->value,
            null,
            $this->dualGlobal(),
            $this->pinnedJiraProject(),
        );

        $this->assertInstanceOf(MessageRef::class, $hint);
        $this->assertSame('issue_tracker_provider.fetch_failed_suggest_override', $hint->key);
    }

    public function testNoHintWhenSingleProviderConfigured(): void
    {
        $hint = $this->builder->build(
            'ENG-1',
            IssueTrackerProvider::Jira->value,
            null,
            [
                'ISSUE_TRACKER_PROVIDERS' => ['jira'],
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_EMAIL' => 'user@example.com',
                'JIRA_API_TOKEN' => 'token',
            ],
            $this->pinnedJiraProject(),
        );

        $this->assertNull($hint);
    }

    /**
     * @return array<string, mixed>
     */
    private function dualGlobal(): array
    {
        return [
            'ISSUE_TRACKER_PROVIDERS' => ['jira', 'linear'],
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
            'LINEAR_API_KEY' => 'lin-key',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pinnedJiraProject(): array
    {
        return [
            'issueTrackerProvider' => IssueTrackerProvider::Jira->value,
            'projectKey' => 'SCI',
            'linearTeamKey' => 'SCIL',
        ];
    }
}
