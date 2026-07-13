<?php

declare(strict_types=1);

namespace App\Guard\DTO;

use App\Enum\WorkItemCommandProfile;

/**
 * Invocation context for unified issue-tracker provider resolution.
 */
final class ProviderResolutionRequest
{
    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     */
    public function __construct(
        public readonly string $commandName,
        public readonly WorkItemCommandProfile $commandProfile,
        public readonly array $globalConfig,
        public readonly ?array $projectConfig,
        public readonly ?string $cliOverride = null,
        public readonly ?string $issueKey = null,
        public readonly ?string $scopeKey = null,
        public readonly ?string $filterName = null,
        public readonly ?string $branchIssueKey = null,
        public readonly ?string $attachmentUrl = null,
    ) {
    }
}
