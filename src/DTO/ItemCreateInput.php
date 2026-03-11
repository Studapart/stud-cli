<?php

declare(strict_types=1);

namespace App\DTO;

final class ItemCreateInput
{
    public function __construct(
        public readonly ?string $project,
        public readonly ?string $type,
        public readonly ?string $summary,
        public readonly ?string $descriptionOption,
        public readonly ?string $descriptionFormat = null,
        public readonly ?string $parentKey = null,
        public readonly ?string $assigneeOption = null,
        public readonly ?string $labelsOption = null,
        public readonly ?string $originalEstimateOption = null,
    ) {
    }
}
