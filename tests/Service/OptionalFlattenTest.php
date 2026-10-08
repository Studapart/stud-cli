<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\ResponseMessage;
use App\Response\CommandResponse;
use App\Service\GitRepository;
use App\Service\OptionalFlatten;
use PHPUnit\Framework\TestCase;

class OptionalFlattenTest extends TestCase
{
    public function testCommitWasCreatedReadsCommitMessageOrFixupSha(): void
    {
        $optional = new OptionalFlatten();

        $this->assertFalse($optional->commitWasCreated(CommandResponse::success('done')));
        $this->assertTrue($optional->commitWasCreated(CommandResponse::success('done', ['commitMessage' => 'feat: one'])));
        $this->assertTrue($optional->commitWasCreated(CommandResponse::success('done', ['fixupSha' => 'abc'])));
    }

    public function testMarkSkippedKeepsFailureAndSetsRewrittenFalse(): void
    {
        $optional = new OptionalFlatten();
        $result = $optional->markSkipped(CommandResponse::error('push failed'));

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->data['rewritten']);
        $this->assertNotEmpty($result->getNotices());
    }

    public function testMergeFailureKeepsHostData(): void
    {
        $optional = new OptionalFlatten();
        $host = CommandResponse::success('committed', ['commitMessage' => 'feat: one']);
        $flatten = CommandResponse::error('rebase failed', data: ['rewritten' => false]);

        $result = $optional->mergeHostAndFlatten($host, $flatten, true);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('feat: one', $result->data['commitMessage']);
        $this->assertFalse($result->data['rewritten']);
    }

    public function testMergeRewriteAddsCompositeWarningOnlyWhenRequested(): void
    {
        $optional = new OptionalFlatten();
        $host = CommandResponse::success('committed', ['commitMessage' => 'feat: one']);
        $flatten = CommandResponse::success('squashed', ['rewritten' => true], [ResponseMessage::warning('hint')]);

        $withHint = $optional->mergeHostAndFlatten($host, $flatten, false);
        $withoutHint = $optional->mergeHostAndFlatten($host, $flatten, true);

        $this->assertTrue($withHint->data['rewritten']);
        $this->assertCount(1, $withHint->getWarnings());
        $this->assertCount(2, $withoutHint->getWarnings());
    }

    public function testWorkingTreeIsDirtyTrimsPorcelain(): void
    {
        $git = $this->createMock(GitRepository::class);
        $git->method('getPorcelainStatus')->willReturn("  \n");
        $optional = new OptionalFlatten();

        $this->assertFalse($optional->workingTreeIsDirty($git));
    }
}
