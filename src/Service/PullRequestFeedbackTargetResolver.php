<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestFeedbackConversation;
use App\DTO\PullRequestFeedbackIds;

class PullRequestFeedbackTargetResolver
{
    private const PROVIDER_GITHUB = 'github';
    private const PROVIDER_GITLAB = 'gitlab';
    private const KIND_REVIEW_THREAD = 'review_thread';
    private const SEPARATOR = ':';
    private const TOKEN_PARTS = 3;

    public function targetForIds(PullRequestFeedbackIds $ids): ?string
    {
        $providerTarget = match ($ids->provider) {
            self::PROVIDER_GITHUB => $ids->threadId,
            self::PROVIDER_GITLAB => $ids->discussionId,
            default => null,
        };

        return $this->targetFor($ids->provider, $ids->kind, $providerTarget);
    }

    public function targetFor(string $provider, string $kind, ?string $providerTarget): ?string
    {
        if ($providerTarget === null || $kind !== self::KIND_REVIEW_THREAD || ! $this->isSupportedProvider($provider)) {
            return null;
        }

        return implode(self::SEPARATOR, [
            $provider,
            $kind,
            rawurlencode($providerTarget),
        ]);
    }

    protected function isSupportedProvider(string $provider): bool
    {
        return $provider === self::PROVIDER_GITHUB || $provider === self::PROVIDER_GITLAB;
    }

    /**
     * @param PullRequestFeedbackConversation[] $conversations
     */
    public function findConversation(string $target, array $conversations): ?PullRequestFeedbackConversation
    {
        if (! $this->isValidTarget($target)) {
            return null;
        }

        foreach ($conversations as $conversation) {
            if ($this->matches($target, $conversation)) {
                return $conversation;
            }
        }

        return null;
    }

    protected function matches(string $target, PullRequestFeedbackConversation $conversation): bool
    {
        $conversationTarget = $conversation->ids->target ?? $this->targetForIds($conversation->ids);

        return $conversationTarget === $target;
    }

    protected function isValidTarget(string $target): bool
    {
        $parts = explode(self::SEPARATOR, $target, self::TOKEN_PARTS);

        return count($parts) === self::TOKEN_PARTS
            && $parts[0] !== ''
            && $parts[1] !== ''
            && rawurldecode($parts[2]) !== '';
    }
}
