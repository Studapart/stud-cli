<?php

declare(strict_types=1);

namespace App\DTO;

final class MessageRef
{
    /**
     * @param array<string, mixed> $parameters
     */
    private function __construct(
        public readonly string $key,
        public readonly array $parameters = [],
        public readonly ?string $fallback = null,
        public readonly ?string $code = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function key(
        string $key,
        array $parameters = [],
        ?string $fallback = null,
        ?string $code = null,
    ): self {
        return new self($key, $parameters, $fallback, $code);
    }

    public function __toString(): string
    {
        return $this->fallback ?? $this->key;
    }
}
