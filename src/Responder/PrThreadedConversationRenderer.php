<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\PullRequestFeedbackComment;
use App\DTO\PullRequestFeedbackConversation;
use App\Response\PrCommentsResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;

/**
 * Renders threaded PR/MR feedback conversations to CLI output.
 */
class PrThreadedConversationRenderer
{
    private const HEADER_SEPARATOR = ' · ';
    private const ACTION_REPLY = 'reply';
    private const ACTION_RESOLVE = 'resolve';
    private const ACTION_REOPEN = 'reopen';

    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        private readonly PrCommentBodyRenderer $bodyRenderer,
    ) {
    }

    public function render(PrCommentsResponse $response): void
    {
        $this->helper->initSection($this->logger, 'pr.comments.threaded_conversations');

        if ($response->conversations === []) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('pr.comments.no_comments'));

            return;
        }

        foreach ($response->conversations as $conversation) {
            $this->renderConversation($conversation);
        }
    }

    protected function renderConversation(PullRequestFeedbackConversation $conversation): void
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->conversationHeader($conversation));
        $metadata = $this->conversationMetadata($conversation);
        if ($metadata !== []) {
            $this->logger->listing(Logger::VERBOSITY_NORMAL, $metadata);
        }

        foreach ($conversation->comments as $comment) {
            $this->renderFeedbackComment($comment);
        }
    }

    protected function renderFeedbackComment(PullRequestFeedbackComment $comment): void
    {
        $this->logger->text(
            Logger::VERBOSITY_NORMAL,
            $this->helper->translator->trans('pr.comments.threaded_comment_meta', [
                'author' => $comment->author,
                'date' => $comment->date->format($this->helper->translator->trans('pr.comments.threaded_date_format')),
            ])
        );

        $this->bodyRenderer->render($comment->body);
    }

    protected function conversationHeader(PullRequestFeedbackConversation $conversation): string
    {
        $parts = [$this->helper->translator->trans('pr.comments.threaded_type_' . $conversation->type)];
        $state = $conversation->state->resolved;
        if ($state !== null) {
            $parts[] = $this->helper->translator->trans($state ? 'pr.comments.threaded_state_resolved' : 'pr.comments.threaded_state_unresolved');
        }
        if ($conversation->state->outdated === true) {
            $parts[] = $this->helper->translator->trans('pr.comments.threaded_state_outdated');
        }
        if ($conversation->truncated) {
            $parts[] = $this->helper->translator->trans('pr.comments.threaded_state_truncated');
        }

        return implode(self::HEADER_SEPARATOR, $parts);
    }

    /**
     * @return string[]
     */
    protected function conversationMetadata(PullRequestFeedbackConversation $conversation): array
    {
        return array_values(array_filter([
            $this->formatConversationId($conversation),
            $this->formatConversationTarget($conversation),
            $this->formatConversationLocation($conversation),
            $this->formatConversationActions($conversation),
        ]));
    }

    protected function formatConversationId(PullRequestFeedbackConversation $conversation): ?string
    {
        $id = $conversation->ids->threadId ?? $conversation->ids->discussionId ?? $conversation->ids->id;

        return $id !== null ? $this->helper->translator->trans('pr.comments.threaded_id', ['id' => $id]) : null;
    }

    protected function formatConversationTarget(PullRequestFeedbackConversation $conversation): ?string
    {
        return $conversation->ids->target !== null
            ? $this->helper->translator->trans('pr.comments.threaded_target', ['target' => $conversation->ids->target])
            : null;
    }

    protected function formatConversationLocation(PullRequestFeedbackConversation $conversation): ?string
    {
        if ($conversation->location?->path === null) {
            return null;
        }
        $location = $conversation->location;
        $value = $location->path . ($location->line !== null ? ':' . $location->line : '');

        return $this->helper->translator->trans('pr.comments.threaded_location', ['location' => $value]);
    }

    protected function formatConversationActions(PullRequestFeedbackConversation $conversation): ?string
    {
        $actions = [];
        foreach ($this->conversationActionLabels($conversation) as $label => $enabled) {
            if ($enabled) {
                $actions[] = $label;
            }
        }

        $value = $actions !== []
            ? implode(', ', $actions)
            : $this->helper->translator->trans('pr.comments.threaded_actions_unsupported');

        return $this->helper->translator->trans('pr.comments.threaded_actions', ['actions' => $value]);
    }

    /**
     * @return array<string, bool>
     */
    protected function conversationActionLabels(PullRequestFeedbackConversation $conversation): array
    {
        return [
            $this->helper->translator->trans('pr.comments.threaded_action_' . self::ACTION_REPLY) => $conversation->actions->canReply,
            $this->helper->translator->trans('pr.comments.threaded_action_' . self::ACTION_RESOLVE) => $conversation->actions->canResolve,
            $this->helper->translator->trans('pr.comments.threaded_action_' . self::ACTION_REOPEN) => $conversation->actions->canReopen,
        ];
    }
}
