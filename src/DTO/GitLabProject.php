<?php

declare(strict_types=1);

namespace App\DTO;

final class GitLabProject
{
    /**
     * @param string $owner Namespace path; nested groups contain slashes (e.g. "group/subgroup")
     * @param string|null $instanceUrl Self-hosted instance URL; null means https://gitlab.com
     */
    public function __construct(
        public readonly string $owner,
        public readonly string $repo,
        public readonly ?string $instanceUrl = null,
    ) {
    }
}
