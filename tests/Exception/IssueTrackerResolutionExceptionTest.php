<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\IssueTrackerResolutionException;
use PHPUnit\Framework\TestCase;

class IssueTrackerResolutionExceptionTest extends TestCase
{
    public function testFactoryMethodsExposeMessageRefs(): void
    {
        $this->assertSame(
            'issue_tracker_provider.ambiguous_prefix',
            IssueTrackerResolutionException::ambiguousPrefix('SCI')->messageRef->key,
        );
        $this->assertSame(
            'issue_tracker_provider.unknown_prefix',
            IssueTrackerResolutionException::unknownPrefix('OPS', 'SCI (jira)')->messageRef->key,
        );
        $this->assertSame(
            'issue_tracker_provider.auto_requires_issue_key',
            IssueTrackerResolutionException::autoRequiresIssueKey()->messageRef->key,
        );
        $this->assertSame(
            'issue_tracker_provider.invalid_override',
            IssueTrackerResolutionException::invalidOverride('bad')->messageRef->key,
        );
        $this->assertSame(
            'issue_tracker_provider.unknown_resolved',
            IssueTrackerResolutionException::unknownResolved('auto')->messageRef->key,
        );
    }
}
