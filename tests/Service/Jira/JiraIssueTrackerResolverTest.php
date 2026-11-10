<?php

declare(strict_types=1);

namespace App\Tests\Service\Jira;

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
        $inner = $this->createStub(IssueTrackerPort::class);
        $wrapped = \App\Tests\Support\ConstructorFixtures::fetchHintPort(
            $inner,
            new IssueTrackerFetchFailureHintBuilder(),
            new \App\DTO\FetchHintContext('jira', null, [], []),
        );
        $resolver = new JiraIssueTrackerResolver();

        $this->assertSame($inner, $resolver->unwrap($wrapped));
        $this->assertFalse($resolver->isJiraBacked($wrapped));
        $this->assertTrue(is_a(JiraIssueTrackerAdapter::class, JiraIssueTrackerCapable::class, true));
    }
}
