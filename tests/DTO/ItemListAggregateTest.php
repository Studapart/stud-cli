<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\ItemListAggregate;
use App\DTO\MessageRef;
use App\DTO\WorkItem;
use App\Enum\ResponseMessageLevel;
use PHPUnit\Framework\TestCase;

class ItemListAggregateTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $aggregate = new ItemListAggregate();

        $this->assertSame([], $aggregate->issues());
        $this->assertSame([], $aggregate->providers());
        $this->assertSame([], $aggregate->warnings());
        $this->assertFalse($aggregate->allProvidersFailed());
        $this->assertFalse($aggregate->isMultiProvider());
    }

    public function testAddIssuesKeepsFirstOccurrenceOfEachKey(): void
    {
        $first = $this->workItem('SCI-1');
        $duplicate = $this->workItem('SCI-1');
        $second = $this->workItem('SCI-2');
        $aggregate = new ItemListAggregate();

        $aggregate->addIssues('jira', [$first]);
        $aggregate->addIssues('linear', [$duplicate, $second]);

        $this->assertSame([$first, $second], $aggregate->issues());
        $this->assertSame(['jira', 'linear'], $aggregate->providers());
        $this->assertTrue($aggregate->isMultiProvider());
    }

    public function testSingleProviderIsNotMultiProvider(): void
    {
        $aggregate = new ItemListAggregate();

        $aggregate->addIssues('jira', [$this->workItem('SCI-1'), $this->workItem('SCI-2')]);

        $this->assertSame(['jira', 'jira'], $aggregate->providers());
        $this->assertFalse($aggregate->isMultiProvider());
    }

    public function testReorderKeepsProviderOfEachIssue(): void
    {
        $jira = $this->workItem('SCI-2');
        $linear = $this->workItem('ENG-1');
        $aggregate = new ItemListAggregate();
        $aggregate->addIssues('jira', [$jira]);
        $aggregate->addIssues('linear', [$linear]);

        $aggregate->reorder([$linear, $jira]);

        $this->assertSame([$linear, $jira], $aggregate->issues());
        $this->assertSame(['linear', 'jira'], $aggregate->providers());
    }

    public function testAddWarningWrapsMessageAsWarning(): void
    {
        $message = MessageRef::key('item.list.error_fetch', ['error' => 'boom']);
        $aggregate = new ItemListAggregate();

        $aggregate->addWarning($message);

        $this->assertCount(1, $aggregate->warnings());
        $this->assertSame(ResponseMessageLevel::Warning, $aggregate->warnings()[0]->level);
        $this->assertSame($message, $aggregate->warnings()[0]->message);
        $this->assertTrue($aggregate->allProvidersFailed());
    }

    public function testNotAllProvidersFailedWhenAnyIssueWasAdded(): void
    {
        $aggregate = new ItemListAggregate();
        $aggregate->addWarning(MessageRef::key('item.list.error_fetch', ['error' => 'boom']));

        $aggregate->addIssues('jira', [$this->workItem('SCI-1')]);

        $this->assertFalse($aggregate->allProvidersFailed());
    }

    private function workItem(string $key): WorkItem
    {
        return new WorkItem('id-' . $key, $key, 'Title', 'To Do', null, '', [], 'Task');
    }
}
