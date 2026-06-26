<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\JiraSupportedTypes;
use App\Service\BranchNameGenerator;
use App\Service\IssueTrackerPort;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BranchNameGeneratorTest extends TestCase
{
    private IssueTrackerPort&MockObject $issueTracker;
    private BranchNameGenerator $generator;

    protected function setUp(): void
    {
        $this->issueTracker = $this->createMock(IssueTrackerPort::class);
        $this->generator = new BranchNameGenerator($this->issueTracker);
    }

    public function testPrefixForIssueTypeMapsKnownTypes(): void
    {
        $this->assertSame(BranchNameGenerator::PREFIX_FIX, BranchNameGenerator::prefixForIssueType(JiraSupportedTypes::Bug->value));
        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, BranchNameGenerator::prefixForIssueType(JiraSupportedTypes::Story->value));
        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, BranchNameGenerator::prefixForIssueType(JiraSupportedTypes::Epic->value));
        $this->assertSame(BranchNameGenerator::PREFIX_CHORE, BranchNameGenerator::prefixForIssueType(JiraSupportedTypes::Task->value));
        $this->assertSame(BranchNameGenerator::PREFIX_CHORE, BranchNameGenerator::prefixForIssueType(JiraSupportedTypes::SubTask->value));
        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, BranchNameGenerator::prefixForIssueType('unknown'));
    }

    public function testPrefixForIssueTypeIsCaseInsensitive(): void
    {
        $this->assertSame(BranchNameGenerator::PREFIX_FIX, $this->generator->getBranchPrefixFromIssueType('Bug'));
    }

    public function testGenerateBranchNameFromKey(): void
    {
        $issue = new \App\DTO\WorkItem(
            '1',
            'SCI-1',
            'Fix login',
            'Open',
            null,
            '',
            [],
            JiraSupportedTypes::Bug->value,
        );
        $this->issueTracker->expects($this->once())->method('getIssue')->with('SCI-1')->willReturn($issue);

        $this->assertSame('fix/SCI-1-fix-login', $this->generator->generateBranchNameFromKey('SCI-1'));
    }
}
