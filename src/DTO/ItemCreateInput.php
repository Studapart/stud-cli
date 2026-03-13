<?php

declare(strict_types=1);

namespace App\DTO;

final class ItemCreateInput
{
    /**
     * @param array<string, string|list<string>>|null $fieldsMap Pre-parsed fields map (agent mode)
     */
    public function __construct(
        public readonly ?string $project,
        public readonly ?string $type,
        public readonly ?string $summary,
        public readonly ?string $descriptionOption,
        public readonly ?string $descriptionFormat = null,
        public readonly ?string $parentKey = null,
        public readonly ?string $fieldsOption = null,
        public readonly ?array $fieldsMap = null,
    ) {
    }
}
