<?php

declare(strict_types=1);

namespace App\Service;

final class ProjectMetadataPromptContext
{
    /**
     * @param array<string, mixed> $globalConfig
     */
    public function __construct(
        public readonly array $globalConfig,
        public readonly ProjectScopeKeyResolver $scope = new ProjectScopeKeyResolver(),
        public readonly ?MessageRenderer $messages = null,
    ) {
    }
}
