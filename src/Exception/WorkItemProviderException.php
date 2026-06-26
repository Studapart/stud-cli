<?php

declare(strict_types=1);

namespace App\Exception;

use App\DTO\MessageRef;

final class WorkItemProviderException extends \RuntimeException
{
    public function __construct(
        public readonly MessageRef $messageRef,
    ) {
        parent::__construct((string) $messageRef);
    }

    public static function missingLinearApiKey(): self
    {
        return new self(MessageRef::key('work_item_provider.missing_linear_api_key'));
    }

    public static function missingJiraConfiguration(): self
    {
        return new self(MessageRef::key('work_item_provider.missing_jira_configuration'));
    }

    public static function notConfigured(): self
    {
        return new self(MessageRef::key('work_item_provider.not_configured'));
    }
}
