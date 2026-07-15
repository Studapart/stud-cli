<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\WorkItem;
use App\Service\CanConvertToMarkdownInterface;
use App\Service\SubmitPrBodyBuilder;
use PHPUnit\Framework\TestCase;

class SubmitPrBodyBuilderTest extends TestCase
{
    public function testBuildUsesWorkItemUrlAndSkipsHtmlConversionForMarkdown(): void
    {
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $htmlConverter->expects($this->never())->method('toMarkdown');
        $recorder = $this->createMock(WorkflowEntryRecorder::class);

        $builder = new SubmitPrBodyBuilder(['JIRA_URL' => 'https://jira.example'], $htmlConverter);
        $issue = new WorkItem(
            id: '1',
            key: 'SCIL-1',
            title: 'T',
            status: 'Todo',
            assignee: 'A',
            description: '## Hello',
            labels: [],
            issueType: 'Task',
            components: [],
            renderedDescription: '## Hello',
            url: 'https://linear.app/x/issue/SCIL-1',
        );

        $body = $builder->build('SCIL-1', $issue, $recorder);

        $this->assertStringContainsString('🔗 **Issue:** [SCIL-1](https://linear.app/x/issue/SCIL-1)', $body);
        $this->assertStringContainsString('## Hello', $body);
    }

    public function testBuildFallsBackToIssueKeyWhenNoUrlConfigured(): void
    {
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $recorder = $this->createMock(WorkflowEntryRecorder::class);
        $builder = new SubmitPrBodyBuilder([], $htmlConverter);

        $body = $builder->build('TPW-1', null, $recorder);

        $this->assertSame("🔗 **Issue:** [TPW-1](TPW-1)\n\nResolves: TPW-1", $body);
    }

    public function testBuildUsesJiraBrowseUrlWhenWorkItemHasNoUrl(): void
    {
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $htmlConverter->method('toMarkdown')->willReturn('md');
        $recorder = $this->createMock(WorkflowEntryRecorder::class);
        $builder = new SubmitPrBodyBuilder(['JIRA_URL' => 'https://jira.example/'], $htmlConverter);
        $issue = new WorkItem(
            id: '1',
            key: 'TPW-1',
            title: 'T',
            status: 'Todo',
            assignee: 'A',
            description: 'd',
            labels: [],
            issueType: 'story',
            components: [],
            renderedDescription: '<p>hi</p>',
        );

        $body = $builder->build('TPW-1', $issue, $recorder);

        $this->assertStringContainsString('https://jira.example/browse/TPW-1', $body);
        $this->assertStringContainsString('md', $body);
    }

    public function testBuildLogsXmlExtensionHintOnDomDocumentFailure(): void
    {
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $htmlConverter->method('toMarkdown')->willThrowException(new \RuntimeException('Class \'DOMDocument\' not found'));
        $recorder = $this->createMock(WorkflowEntryRecorder::class);
        $recorder->expects($this->once())->method('addWarning');
        $builder = new SubmitPrBodyBuilder(['JIRA_URL' => 'https://jira.example'], $htmlConverter);
        $issue = new WorkItem(
            id: '1',
            key: 'TPW-1',
            title: 'T',
            status: 'Todo',
            assignee: 'A',
            description: 'd',
            labels: [],
            issueType: 'story',
            components: [],
            renderedDescription: '<p>hi</p>',
        );

        $body = $builder->build('TPW-1', $issue, $recorder);

        $this->assertStringContainsString('<p>hi</p>', $body);
    }

    public function testBuildFallsBackToDescriptionWhenRenderedEmptyAndUrlPresent(): void
    {
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $htmlConverter->expects($this->never())->method('toMarkdown');
        $recorder = $this->createMock(WorkflowEntryRecorder::class);
        $builder = new SubmitPrBodyBuilder([], $htmlConverter);
        $issue = new WorkItem(
            id: '1',
            key: 'SCIL-1',
            title: 'T',
            status: 'Todo',
            assignee: 'A',
            description: 'plain md',
            labels: [],
            issueType: 'Task',
            components: [],
            renderedDescription: '',
            url: 'https://linear.app/x/issue/SCIL-1',
        );

        $body = $builder->build('SCIL-1', $issue, $recorder);

        $this->assertStringContainsString('plain md', $body);
    }

    public function testBuildReturnsResolvesWhenDescriptionIsPlaceholder(): void
    {
        $htmlConverter = $this->createMock(CanConvertToMarkdownInterface::class);
        $recorder = $this->createMock(WorkflowEntryRecorder::class);
        $builder = new SubmitPrBodyBuilder(['JIRA_URL' => 'https://jira.example'], $htmlConverter);
        $issue = new WorkItem(
            id: '1',
            key: 'SCIL-1',
            title: 'T',
            status: 'Todo',
            assignee: 'A',
            description: 'No description provided.',
            labels: [],
            issueType: 'Task',
            components: [],
            renderedDescription: 'No description provided.',
            url: 'https://linear.app/x/issue/SCIL-1',
        );

        $body = $builder->build('SCIL-1', $issue, $recorder);

        $this->assertSame(
            "🔗 **Issue:** [SCIL-1](https://linear.app/x/issue/SCIL-1)\n\nResolves: https://linear.app/x/issue/SCIL-1",
            $body,
        );
    }
}
