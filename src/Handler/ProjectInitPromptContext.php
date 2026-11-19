<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\ProjectMetadataPromptService;
use App\Service\ProjectScopeKeyResolver;

final class ProjectInitPromptContext
{
    public function __construct(
        public readonly ProjectInitGlobalConfig $global,
        public readonly ProjectMetadataPromptService $metadata,
        public readonly ProjectScopeKeyResolver $scope = new ProjectScopeKeyResolver(),
    ) {
    }
}
