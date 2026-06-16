<?php

declare(strict_types=1);

namespace App\Response;

use App\DTO\MessageRef;

final class PrCommentResponse extends AbstractResponse
{
    private const ACTION_POSTED = 'posted';
    private const ACTION_REPLIED = 'replied';

    private function __construct(
        bool $success = true,
        MessageRef|string|null $error = null,
        public readonly MessageRef|string $message = '',
        public readonly string $action = self::ACTION_POSTED,
        public readonly int $pullNumber = 0,
        public readonly ?string $target = null,
        public readonly bool $resolved = false,
    ) {
        parent::__construct($success, $error);
    }

    public static function posted(MessageRef|string $message, int $pullNumber): self
    {
        return new self(
            message: $message,
            action: self::ACTION_POSTED,
            pullNumber: $pullNumber,
        );
    }

    public static function replied(MessageRef|string $message, int $pullNumber, string $target, bool $resolved): self
    {
        return new self(
            message: $message,
            action: self::ACTION_REPLIED,
            pullNumber: $pullNumber,
            target: $target,
            resolved: $resolved,
        );
    }

    public static function error(MessageRef|string $error): self
    {
        return new self(success: false, error: $error);
    }
}
