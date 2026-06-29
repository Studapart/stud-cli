<?php

declare(strict_types=1);

namespace App\Exception;

use App\DTO\MessageRef;

final class StudConfigException extends \RuntimeException
{
    public function __construct(
        public readonly MessageRef $messageRef,
    ) {
        parent::__construct((string) $messageRef);
    }

    public static function baseBranchRequired(): self
    {
        return new self(MessageRef::key('config.base_branch_required'));
    }

    public static function baseBranchInvalid(string $branch): self
    {
        return new self(MessageRef::key('config.base_branch_invalid', ['branch' => $branch]));
    }

    public static function baseBranchNameEmpty(): self
    {
        return new self(MessageRef::key('config.base_branch_name_empty'));
    }

    public static function baseBranchAutoDetectFailed(): self
    {
        return new self(MessageRef::key('config.base_branch_auto_detect_failed'));
    }

    public static function baseBranchDefaultMissing(string $branch): self
    {
        return new self(MessageRef::key('config.base_branch_default_missing', ['branch' => $branch]));
    }

    public static function gitProviderRequired(): self
    {
        return new self(MessageRef::key('config.git_provider_required'));
    }

    public static function gitProviderQuietUnavailable(): self
    {
        return new self(MessageRef::key('config.git_provider_quiet_unavailable'));
    }

    public static function gitTokenRequired(): self
    {
        return new self(MessageRef::key('config.git_token_required'));
    }

    public static function gitProviderNotConfigured(): self
    {
        return new self(MessageRef::key('config.git_provider_not_configured'));
    }

    public static function invalidJiraBaseUrl(): self
    {
        return new self(MessageRef::key('work_item_provider.invalid_jira_base_url'));
    }

    public static function linearTeamKeyRequired(): self
    {
        return new self(MessageRef::key('item.create.error_no_linear_team'));
    }
}
