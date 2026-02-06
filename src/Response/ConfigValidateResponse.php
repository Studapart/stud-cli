<?php

declare(strict_types=1);

namespace App\Response;

/**
 * Response DTO for config:validate command.
 * Carries per-component status: Jira and Git provider (ok, fail, or skipped).
 */
final class ConfigValidateResponse extends AbstractResponse
{
    public const STATUS_OK = 'ok';
    public const STATUS_FAIL = 'fail';
    public const STATUS_SKIPPED = 'skipped';

    private function __construct(
        bool $success,
        ?string $error,
        public readonly string $jiraStatus,
        public readonly ?string $jiraMessage,
        public readonly string $gitStatus,
        public readonly ?string $gitMessage
    ) {
        parent::__construct($success, $error);
    }

    /**
     * Build a successful response with per-component statuses.
     */
    public static function create(
        string $jiraStatus,
        ?string $jiraMessage,
        string $gitStatus,
        ?string $gitMessage
    ): self {
        $success = ($jiraStatus !== self::STATUS_FAIL) && ($gitStatus !== self::STATUS_FAIL);

        return new self(
            $success,
            null,
            $jiraStatus,
            $jiraMessage,
            $gitStatus,
            $gitMessage
        );
    }

    public static function error(string $error): self
    {
        return new self(
            false,
            $error,
            self::STATUS_FAIL,
            null,
            self::STATUS_FAIL,
            null
        );
    }
}
