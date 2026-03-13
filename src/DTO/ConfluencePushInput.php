<?php

declare(strict_types=1);

namespace App\DTO;

final class ConfluencePushInput
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $space = null,
        public readonly ?string $title = null,
        public readonly ?string $pageId = null,
        public readonly ?string $parentId = null,
        public readonly string $status = 'current',
        public readonly ?string $contactAccountId = null,
        public readonly ?string $contactDisplayName = null,
    ) {
    }
}
