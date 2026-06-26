<?php

declare(strict_types=1);

namespace App\Response;

/**
 * Response DTO for config:validate command.
 * Carries per-component status: Jira, Git provider, and Linear (ok, fail, or skipped).
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
        public readonly ?string $gitMessage,
        public readonly string $linearStatus,
        public readonly ?string $linearMessage,
        array $messages = [],
    ) {
        parent::__construct($success, $error, $messages);
    }

    /**
     * Build a successful response with per-component statuses.
     *
     * @param list<\App\DTO\ResponseMessage> $messages
     */
    public static function create(
        string $jiraStatus,
        ?string $jiraMessage,
        string $gitStatus,
        ?string $gitMessage,
        string $linearStatus = self::STATUS_SKIPPED,
        ?string $linearMessage = null,
        array $messages = [],
    ): self {
        $success = ($jiraStatus !== self::STATUS_FAIL)
            && ($gitStatus !== self::STATUS_FAIL)
            && ($linearStatus !== self::STATUS_FAIL);

        return new self(
            $success,
            null,
            $jiraStatus,
            $jiraMessage,
            $gitStatus,
            $gitMessage,
            $linearStatus,
            $linearMessage,
            $messages,
        );
    }

    /**
     * @param list<\App\DTO\ResponseMessage> $messages
     */
    public function withAdditionalMessages(array $messages): self
    {
        return new self(
            $this->success,
            $this->getError(),
            $this->jiraStatus,
            $this->jiraMessage,
            $this->gitStatus,
            $this->gitMessage,
            $this->linearStatus,
            $this->linearMessage,
            array_merge($this->messages, $messages),
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
            null,
            self::STATUS_FAIL,
            null,
        );
    }
}
