<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\PrCommentRequest;
use App\DTO\PullRequestFeedbackConversation;
use App\Response\PrCommentResponse;
use App\Service\GitProviderInterface;
use App\Service\GitRepository;
use App\Service\MarkdownHelper;
use App\Service\PullRequestFeedbackTargetResolver;

class PrCommentHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ?GitProviderInterface $gitProvider,
        mixed $_translator,
        private readonly PullRequestFeedbackTargetResolver $targetResolver = new PullRequestFeedbackTargetResolver(),
    ) {
        unset($_translator);
    }

    public function handle(PrCommentRequest $request): PrCommentResponse
    {
        if (! $this->gitProvider) {
            return PrCommentResponse::error(MessageRef::key('pr.comment.error_no_provider'));
        }

        $commentBody = $this->prepareCommentBody($request->message);

        if ($commentBody === null) {
            return PrCommentResponse::error(MessageRef::key('pr.comment.error_no_input'));
        }

        $prNumber = $this->findActivePullRequest();
        if ($prNumber === null) {
            return PrCommentResponse::error(MessageRef::key('pr.comment.error_no_pr'));
        }

        try {
            if ($request->isReply()) {
                return $this->replyToFeedback($prNumber, $request, $commentBody);
            }

            $this->gitProvider->createComment($prNumber, $commentBody);

            return PrCommentResponse::posted(
                MessageRef::key('pr.comment.success', ['number' => $prNumber]),
                $prNumber
            );
        } catch (\Exception $e) {
            return PrCommentResponse::error(MessageRef::key('pr.comment.error_post', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Returns the normalized comment body, or null when no message was provided.
     */
    protected function prepareCommentBody(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        return MarkdownHelper::unescapeCheckboxMarkdown(trim($message));
    }

    /**
     * Finds the active Pull Request number for the current branch.
     */
    protected function findActivePullRequest(): ?int
    {
        $branch = $this->gitRepository->getCurrentBranchName();
        $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
        $headBranch = $remoteOwner ? "{$remoteOwner}:{$branch}" : $branch;

        try {
            $pr = $this->gitProvider?->findPullRequestByBranch($headBranch);

            return is_array($pr) && isset($pr['number']) ? (int) $pr['number'] : null;
        } catch (\Exception) {
            return null;
        }
    }

    protected function replyToFeedback(int $prNumber, PrCommentRequest $request, string $commentBody): PrCommentResponse
    {
        $target = trim((string) $request->replyTo);
        $conversation = $this->findTargetConversation($prNumber, $target);
        if ($conversation === null) {
            return PrCommentResponse::error(MessageRef::key('pr.comment.error_invalid_target', ['target' => $target]));
        }

        if (! $conversation->actions->canReply) {
            return PrCommentResponse::error(MessageRef::key('pr.comment.error_reply_unsupported', ['target' => $target]));
        }

        if ($request->resolve && ! $conversation->actions->canResolve) {
            return PrCommentResponse::error($this->resolveUnsupportedError($conversation, $target));
        }

        $this->gitProvider?->replyToPullRequestFeedback($prNumber, $conversation->ids, $commentBody);
        if ($request->resolve) {
            return $this->resolveAfterReply($prNumber, $conversation, $target);
        }

        return PrCommentResponse::replied(
            MessageRef::key('pr.comment.reply_success', ['number' => $prNumber]),
            $prNumber,
            $target,
            false
        );
    }

    protected function findTargetConversation(int $prNumber, string $target): ?PullRequestFeedbackConversation
    {
        return $this->targetResolver->findConversation(
            $target,
            $this->gitProvider?->getPullRequestFeedbackConversations($prNumber) ?? []
        );
    }

    protected function resolveUnsupportedError(PullRequestFeedbackConversation $conversation, string $target): MessageRef
    {
        $key = $conversation->state->resolved === true
            ? 'pr.comment.error_already_resolved'
            : 'pr.comment.error_resolve_unsupported';

        return MessageRef::key($key, ['target' => $target]);
    }

    protected function resolveAfterReply(
        int $prNumber,
        PullRequestFeedbackConversation $conversation,
        string $target,
    ): PrCommentResponse {
        try {
            $this->gitProvider?->resolvePullRequestFeedback($prNumber, $conversation->ids);
        } catch (\Exception $e) {
            return PrCommentResponse::error(MessageRef::key('pr.comment.error_resolve_after_reply', ['error' => $e->getMessage()]));
        }

        return PrCommentResponse::replied(
            MessageRef::key('pr.comment.reply_resolve_success', ['number' => $prNumber]),
            $prNumber,
            $target,
            true
        );
    }
}
