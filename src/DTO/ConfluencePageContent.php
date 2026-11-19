<?php

declare(strict_types=1);

namespace App\DTO;

final class ConfluencePageContent
{
    /**
     * @param string $adfJsonString Stringified ADF document (atlas_doc_format)
     */
    public function __construct(
        public readonly string $title,
        public readonly string $adfJsonString,
    ) {
    }
}
