<?php

declare(strict_types=1);

namespace App\DTO;

final class ConfluenceShowInput
{
    public function __construct(
        public readonly ?string $pageId = null,
        public readonly ?string $url = null,
    ) {
    }
}
