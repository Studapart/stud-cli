<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Values already known for an item:create request, used to fill or prompt extra required createmeta fields.
 */
final class ExtraFieldPromptContext
{
    /**
     * @param array<string, mixed>|null $descriptionAdf
     */
    public function __construct(
        public readonly IssueCreationState $state,
        public readonly bool $typeExplicitlyProvided,
        public readonly ?array $descriptionAdf,
    ) {
    }
}
