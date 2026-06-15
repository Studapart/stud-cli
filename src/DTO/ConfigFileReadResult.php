<?php

declare(strict_types=1);

namespace App\DTO;

final class ConfigFileReadResult
{
    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        public readonly array $config,
        public readonly ?string $readFailureMessage,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function readable(array $config): self
    {
        return new self($config, null);
    }

    public static function missing(): self
    {
        return new self([], null);
    }

    public static function unreadable(string $message): self
    {
        return new self([], $message);
    }

    public function isUnreadable(): bool
    {
        return $this->readFailureMessage !== null;
    }
}
