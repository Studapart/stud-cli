<?php

declare(strict_types=1);

namespace App\DTO;

final class PrCommentRequest
{
    public function __construct(
        public readonly ?string $message,
        public readonly ?string $replyTo = null,
        public readonly bool $resolve = false,
    ) {
    }

    public function isReply(): bool
    {
        return $this->replyTo !== null && trim($this->replyTo) !== '';
    }
}
