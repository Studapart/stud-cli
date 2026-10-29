<?php

declare(strict_types=1);

namespace App\Tests\Service\Jira;

use App\Exception\ApiException;
use App\Service\DurationParser;
use App\Service\FieldsParser;
use App\Service\IssueTrackerPort;
use App\Service\Jira\JiraIssueFieldWriter;
use PHPUnit\Framework\TestCase;

class JiraIssueFieldWriterTest extends TestCase
{
    public function testEmptyInputDoesNotCallTheTracker(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->expects($this->never())->method('getEditMetaFields');
        $writer = new JiraIssueFieldWriter(new FieldsParser(new DurationParser()));

        $this->assertSame([], $writer->applyFields('SCI-1', [], $port));
    }

    public function testEditMetaFailureWarnsWithInputKeys(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('getEditMetaFields')->willThrowException(new ApiException('meta', 'HTTP 500', 500));
        $writer = new JiraIssueFieldWriter(new FieldsParser(new DurationParser()));

        $this->assertSame(['labels'], $writer->applyFields('SCI-1', ['labels' => ['A']], $port));
    }

    public function testUnknownFieldIsNamedInWarnings(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);
        $port->method('getEditMetaFields')->willReturn([]);
        $port->expects($this->never())->method('update');
        $writer = new JiraIssueFieldWriter(new FieldsParser(new DurationParser()));

        $this->assertSame(['missing'], $writer->applyFields('SCI-1', ['missing' => 'x'], $port));
    }
}
