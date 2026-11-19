<?php

namespace App\Tests\Responder;

use App\DTO\WorkItem;
use App\Responder\ItemListTable;
use App\Response\ItemListResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;

class ItemListTableTest extends CommandTestCase
{
    public function testRenderWritesKeyStatusAndSummaryColumns(): void
    {
        $response = ItemListResponse::success([new WorkItem('1', 'TPW-35', 'Title', 'To Do', 'user', 'desc', [], 'Task')], false, null);
        /** @var Logger&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('table')
            ->with(Logger::VERBOSITY_NORMAL, ['table.key', 'table.status', 'table.summary'], [['TPW-35', 'To Do', 'Title']]);

        (new ItemListTable(new ResponderHelper($this->translationService), $logger))->render($response);
    }

    public function testRenderAddsProviderColumnForMultiProviderResults(): void
    {
        $response = ItemListResponse::success(
            [
                new WorkItem('1', 'SCI-1', 'Jira issue', 'Open', 'user', 'desc', [], 'Story'),
                new WorkItem('2', 'ENG-1', 'Linear issue', 'Open', 'user', 'desc', [], 'Story'),
            ],
            false,
            null,
            ['jira', 'linear'],
            true,
        );
        /** @var Logger&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('table')
            ->with(
                Logger::VERBOSITY_NORMAL,
                ['table.key', 'table.status', 'table.summary', 'table.provider'],
                [['SCI-1', 'Open', 'Jira issue', 'jira'], ['ENG-1', 'Open', 'Linear issue', 'linear']],
            );

        (new ItemListTable(new ResponderHelper($this->translationService), $logger))->render($response);
    }

    public function testProviderLabelForIssueReturnsEmptyWhenKeyMissing(): void
    {
        $table = new class (new ResponderHelper($this->translationService), $this->createStub(Logger::class)) extends ItemListTable {
            public function exposedProviderLabel(ItemListResponse $response, string $issueKey): string
            {
                return $this->providerLabelForIssue($response, $issueKey);
            }
        };
        $response = ItemListResponse::success([], false, null, [], false);

        $this->assertSame('', $table->exposedProviderLabel($response, 'missing-key'));
    }
}
