<?php

declare(strict_types=1);

namespace App\Tests\Service\Jira;

use App\Service\FetchHintIssueTrackerPort;
use App\Service\IssueTrackerFetchFailureHintBuilder;
use App\Service\IssueTrackerPort;
use App\Service\Jira\JiraIssueTrackerCapable;
use App\Service\Jira\JiraIssueTrackerResolver;
use App\Service\JiraIssueTrackerAdapter;
use PHPUnit\Framework\TestCase;

class JiraIssueTrackerResolverTest extends TestCase
{
    public function testUnwrapsFetchHintAndDetectsJiraAdapter(): void
    {
        $inner = $this->createMock(IssueTrackerPort::class);
        $wrapped = new FetchHintIssueTrackerPort(
            $inner,
            new IssueTrackerFetchFailureHintBuilder(),
            'jira',
            null,
            [],
            [],
        );
        $resolver = new JiraIssueTrackerResolver();

        $this->assertSame($inner, $resolver->unwrap($wrapped));
        $this->assertFalse($resolver->isJiraBacked($wrapped));
        $this->assertTrue(is_a(JiraIssueTrackerAdapter::class, JiraIssueTrackerCapable::class, true));
    }
}
