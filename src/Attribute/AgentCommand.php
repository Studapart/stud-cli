<?php

declare(strict_types=1);

namespace App\Attribute;

/**
 * Describes agent-facing command metadata for runtime schema discovery.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION)]
final class AgentCommand
{
    public function __construct(
        public readonly bool $essential = false,
    ) {
    }
}
