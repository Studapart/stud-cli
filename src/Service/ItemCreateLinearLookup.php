<?php

declare(strict_types=1);

namespace App\Service;

final class ItemCreateLinearLookup
{
    public function __construct(
        public readonly ?LinearApiClient $client = null,
        public readonly ?Logger $logger = null,
        public readonly ProjectScopeKeyResolver $scope = new ProjectScopeKeyResolver(),
    ) {
    }
}
