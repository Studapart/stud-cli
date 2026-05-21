<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\PullRequestFeedbackActions;
use App\DTO\PullRequestFeedbackConversation;
use App\DTO\PullRequestFeedbackIds;
use App\DTO\PullRequestFeedbackState;
use App\Service\PullRequestFeedbackTargetResolver;
use PHPUnit\Framework\TestCase;

class PullRequestFeedbackTargetResolverTest extends TestCase
{
    private PullRequestFeedbackTargetResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PullRequestFeedbackTargetResolver();
    }

    public function testTargetForGithubReviewThread(): void
    {
        $target = $this->resolver->targetFor('github', 'review_thread', 'thread/id');

        $this->assertSame('github:review_thread:thread%2Fid', $target);
    }

    public function testTargetForGitLabReviewThread(): void
    {
        $target = $this->resolver->targetFor('gitlab', 'review_thread', 'discussion:id');

        $this->assertSame('gitlab:review_thread:discussion%3Aid', $target);
    }

    public function testTargetForIdsUsesProviderSpecificReference(): void
    {
        $githubTarget = $this->resolver->targetForIds(new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread/id'));
        $gitLabTarget = $this->resolver->targetForIds(new PullRequestFeedbackIds('gitlab', 'review_thread', discussionId: 'discussion:id'));

        $this->assertSame('github:review_thread:thread%2Fid', $githubTarget);
        $this->assertSame('gitlab:review_thread:discussion%3Aid', $gitLabTarget);
    }

    public function testTargetForIdsReturnsNullForUnsupportedIds(): void
    {
        $this->assertNull($this->resolver->targetFor('github', 'pr_comment', '1'));
        $this->assertNull($this->resolver->targetFor('github', 'review_thread', null));
        $this->assertNull($this->resolver->targetFor('unknown', 'review_thread', '1'));
        $this->assertNull($this->resolver->targetForIds(new PullRequestFeedbackIds('unknown', 'review_thread', threadId: '1')));
    }

    public function testFindConversationMatchesExistingTarget(): void
    {
        $ids = new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread-1', target: 'github:review_thread:thread-1');
        $conversation = $this->conversation($ids);

        $result = $this->resolver->findConversation('github:review_thread:thread-1', [$conversation]);

        $this->assertSame($conversation, $result);
    }

    public function testFindConversationMatchesComputedTarget(): void
    {
        $ids = new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread-1');
        $conversation = $this->conversation($ids);

        $result = $this->resolver->findConversation('github:review_thread:thread-1', [$conversation]);

        $this->assertSame($conversation, $result);
    }

    public function testFindConversationRejectsInvalidTarget(): void
    {
        $this->assertNull($this->resolver->findConversation('invalid', []));
    }

    public function testFindConversationReturnsNullWhenNoConversationMatches(): void
    {
        $conversation = $this->conversation(new PullRequestFeedbackIds('github', 'review_thread', threadId: 'thread-1'));

        $result = $this->resolver->findConversation('github:review_thread:thread-2', [$conversation]);

        $this->assertNull($result);
    }

    private function conversation(PullRequestFeedbackIds $ids): PullRequestFeedbackConversation
    {
        return new PullRequestFeedbackConversation(
            $ids,
            'review_thread',
            new PullRequestFeedbackState(),
            [],
            new PullRequestFeedbackActions(canReply: true),
        );
    }
}
