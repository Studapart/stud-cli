<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Response\CommandResponse;
use PHPUnit\Framework\TestCase;

class SubmitFlattenStateTest extends TestCase
{
    public function testFlattenNotRequestedOmitsRewritten(): void
    {
        $state = _submit_flatten_state(false, null);

        $this->assertFalse($state['alreadyPublished']);
        $this->assertNull($state['rewritten']);
        $this->assertSame([], $state['diagnostics']);
    }

    public function testFlattenWithoutStageAllRecordsRewrittenFalse(): void
    {
        $state = _submit_flatten_state(true, null);

        $this->assertFalse($state['alreadyPublished']);
        $this->assertFalse($state['rewritten']);
        $this->assertSame([], $state['diagnostics']);
    }

    public function testPublishedCreatedCommitKeepsRewrittenFromPush(): void
    {
        $push = CommandResponse::success('pushed', [
            'published' => true,
            'rewritten' => true,
            'commit' => ['commitMessage' => 'feat: one'],
        ]);

        $state = _submit_flatten_state(true, $push);

        $this->assertTrue($state['alreadyPublished']);
        $this->assertTrue($state['rewritten']);
    }
}
