<?php

declare(strict_types=1);

namespace App\Attribute;

/**
 * Describes the output schema for a task's agent mode response.
 *
 * Two usage modes:
 * - DTO-based: set responseClass to a Response DTO class; the schema generator
 *   reflects on its public properties to build the output schema.
 * - Explicit: set properties to a map of property names to types for commands
 *   that return simple messages (int/void handlers).
 */
#[\Attribute(\Attribute::TARGET_FUNCTION)]
final class AgentOutput
{
    /**
     * @param class-string|null         $responseClass Response DTO class for reflection-based schema
     * @param array<string, string>     $properties    Explicit property→type map (e.g., ['message' => 'string'])
     * @param string|null               $description   Human-readable description of the output
     */
    public function __construct(
        public readonly ?string $responseClass = null,
        public readonly array $properties = [],
        public readonly ?string $description = null,
    ) {
    }
}
