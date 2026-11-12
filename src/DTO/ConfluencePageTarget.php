<?php

declare(strict_types=1);

namespace App\DTO;

final class ConfluencePageTarget
{
    /**
     * @param string $spaceId Numeric space ID
     * @param string|null $parentId Optional parent page ID
     * @param string $status Page status: "current" (published) or "draft"
     */
    public function __construct(
        public readonly string $spaceId,
        public readonly ?string $parentId = null,
        public readonly string $status = 'current',
    ) {
    }
}
