<?php

declare(strict_types=1);

namespace App\Tests\Service\Jira;

use App\Service\Jira\JiraIssueFieldKeys;
use App\Service\Jira\JiraStudFieldMapper;
use App\Service\StudIssueKeys;
use PHPUnit\Framework\TestCase;

class JiraStudFieldMapperTest extends TestCase
{
    private JiraStudFieldMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new JiraStudFieldMapper();
    }

    public function testMapsStandardStudFieldsToJira(): void
    {
        $stud = [
            StudIssueKeys::PROJECT => [StudIssueKeys::KEY => 'SCI'],
            StudIssueKeys::ISSUE_TYPE => [StudIssueKeys::ID => '10001', StudIssueKeys::NAME => 'Story'],
            StudIssueKeys::TITLE => 'Implement feature',
            StudIssueKeys::DESCRIPTION => ['type' => 'doc'],
            StudIssueKeys::PARENT => [StudIssueKeys::KEY => 'SCI-1'],
            StudIssueKeys::LABELS => ['bug'],
            StudIssueKeys::PRIORITY => [StudIssueKeys::NAME => 'High'],
            StudIssueKeys::ASSIGNEE => [StudIssueKeys::ACCOUNT_ID => 'acc-1'],
            StudIssueKeys::REPORTER => [StudIssueKeys::ACCOUNT_ID => 'acc-2'],
        ];

        $jira = $this->mapper->toJiraCreateOrUpdateFields($stud);

        $this->assertSame([JiraIssueFieldKeys::KEY => 'SCI'], $jira[JiraIssueFieldKeys::PROJECT]);
        $this->assertSame(
            [JiraIssueFieldKeys::ID => '10001', JiraIssueFieldKeys::NAME => 'Story'],
            $jira[JiraIssueFieldKeys::ISSUE_TYPE],
        );
        $this->assertSame('Implement feature', $jira[JiraIssueFieldKeys::SUMMARY]);
        $this->assertSame(['type' => 'doc'], $jira[JiraIssueFieldKeys::DESCRIPTION]);
        $this->assertSame([JiraIssueFieldKeys::KEY => 'SCI-1'], $jira[JiraIssueFieldKeys::PARENT]);
        $this->assertSame(['bug'], $jira[JiraIssueFieldKeys::LABELS]);
        $this->assertSame([JiraIssueFieldKeys::NAME => 'High'], $jira[JiraIssueFieldKeys::PRIORITY]);
        $this->assertSame([JiraIssueFieldKeys::ACCOUNT_ID => 'acc-1'], $jira[JiraIssueFieldKeys::ASSIGNEE]);
        $this->assertSame([JiraIssueFieldKeys::ACCOUNT_ID => 'acc-2'], $jira[JiraIssueFieldKeys::REPORTER]);
    }

    public function testPassesThroughUnknownKeys(): void
    {
        $stud = [
            'customfield_10001' => 'value',
            'timeoriginalestimate' => 3600,
        ];

        $jira = $this->mapper->toJiraCreateOrUpdateFields($stud);

        $this->assertSame('value', $jira['customfield_10001']);
        $this->assertSame(3600, $jira['timeoriginalestimate']);
    }

    public function testMapsIssueTypeNestedKeysOnlyForIssueType(): void
    {
        $stud = [
            StudIssueKeys::PROJECT => [
                StudIssueKeys::KEY => 'SCI',
                StudIssueKeys::ID => 'ignored-on-project',
            ],
        ];

        $jira = $this->mapper->toJiraCreateOrUpdateFields($stud);

        $this->assertSame(
            [JiraIssueFieldKeys::KEY => 'SCI', StudIssueKeys::ID => 'ignored-on-project'],
            $jira[JiraIssueFieldKeys::PROJECT],
        );
    }

    public function testMapsAllIssueTypeNestedStudKeys(): void
    {
        $stud = [
            StudIssueKeys::ISSUE_TYPE => [
                StudIssueKeys::ID => '10001',
                StudIssueKeys::NAME => 'Story',
                StudIssueKeys::KEY => 'story-key',
                StudIssueKeys::ACCOUNT_ID => 'acc-type',
                'customNested' => 'preserved',
            ],
        ];

        $jira = $this->mapper->toJiraCreateOrUpdateFields($stud);

        $this->assertSame(
            [
                JiraIssueFieldKeys::ID => '10001',
                JiraIssueFieldKeys::NAME => 'Story',
                JiraIssueFieldKeys::KEY => 'story-key',
                JiraIssueFieldKeys::ACCOUNT_ID => 'acc-type',
                'customNested' => 'preserved',
            ],
            $jira[JiraIssueFieldKeys::ISSUE_TYPE],
        );
    }

    public function testPassesThroughScalarValuesUnchanged(): void
    {
        $jira = $this->mapper->toJiraCreateOrUpdateFields([
            StudIssueKeys::TITLE => 'Plain title',
            'customfield_10001' => 42,
        ]);

        $this->assertSame('Plain title', $jira[JiraIssueFieldKeys::SUMMARY]);
        $this->assertSame(42, $jira['customfield_10001']);
    }
}
