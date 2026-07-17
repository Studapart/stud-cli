<?php

declare(strict_types=1);

namespace App\Exception;

use App\DTO\MessageRef;

final class IssueTrackerResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly MessageRef $messageRef,
    ) {
        parent::__construct((string) $messageRef);
    }

    public static function ambiguousPrefix(string $prefix): self
    {
        return new self(MessageRef::key('issue_tracker_provider.ambiguous_prefix', ['%prefix%' => $prefix]));
    }

    public static function unknownPrefix(string $prefix, string $configuredKeys): self
    {
        return new self(MessageRef::key('issue_tracker_provider.unknown_prefix', [
            '%prefix%' => $prefix,
            '%configuredKeys%' => $configuredKeys,
        ]));
    }

    public static function autoRequiresIssueKey(): self
    {
        return new self(MessageRef::key('issue_tracker_provider.auto_requires_issue_key'));
    }

    public static function invalidOverride(string $override): self
    {
        return new self(MessageRef::key('issue_tracker_provider.invalid_override', ['%value%' => $override]));
    }

    public static function unknownResolved(string $provider): self
    {
        return new self(MessageRef::key('issue_tracker_provider.unknown_resolved', ['%value%' => $provider]));
    }
}
