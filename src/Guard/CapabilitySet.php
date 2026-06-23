<?php

declare(strict_types=1);

namespace App\Guard;

/**
 * Immutable set of capability marker interface class names.
 */
class CapabilitySet
{
    /**
     * @param list<class-string> $capabilities
     */
    public function __construct(
        private readonly array $capabilities = [],
    ) {
    }

    /**
     * @param list<class-string> $capabilities
     */
    public static function fromList(array $capabilities): self
    {
        return new self(array_values(array_unique($capabilities)));
    }

    /**
     * @return list<class-string>
     */
    public function all(): array
    {
        return $this->capabilities;
    }

    public function isEmpty(): bool
    {
        return $this->capabilities === [];
    }

    public function has(string $capabilityClass): bool
    {
        return in_array($capabilityClass, $this->capabilities, true);
    }
}
