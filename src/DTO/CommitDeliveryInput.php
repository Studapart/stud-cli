<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Inputs for a commit that may autosquash fixups afterward and never pushes.
 */
final class CommitDeliveryInput
{
    public function __construct(
        public readonly bool $isNew = false,
        public readonly ?string $message = null,
        public readonly bool $stageAll = false,
        public readonly bool $quiet = false,
        public readonly bool $flatten = false,
    ) {
    }
}
