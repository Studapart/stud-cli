<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Stud-cli workflow picker row (Jira transition or Linear workflow state).
 */
final class StateChange
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $targetStatus = null,
        public readonly ?string $type = null,
    ) {
    }
}
