<?php

declare(strict_types=1);

namespace App\DTO;

final class ItemUpdateInput
{
    /**
     * @param array<string, string|list<string>>|null $fieldsMap Pre-parsed fields map (agent mode)
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $summary = null,
        public readonly ?string $descriptionOption = null,
        public readonly ?string $descriptionFormat = null,
        public readonly ?string $fieldsOption = null,
        public readonly ?array $fieldsMap = null,
    ) {
    }
}
