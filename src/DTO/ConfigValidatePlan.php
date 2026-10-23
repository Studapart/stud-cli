<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\GitProvider;
use App\Enum\IssueTrackerProvider;

/**
 * Immutable probe and project-selection plan for config:validate.
 */
final class ConfigValidatePlan
{
    public function __construct(
        public readonly bool $inRepo,
        public readonly bool $hasProjectConfig,
        public readonly bool $probeJira,
        public readonly bool $probeLinear,
        public readonly bool $probeGit,
        public readonly bool $includeGithub,
        public readonly bool $includeGitlab,
        public readonly ?IssueTrackerProvider $selectedTracker,
        public readonly ?MessageRef $projectSelectionError,
        public readonly ?GitProvider $projectGit,
        public readonly bool $skipJira = false,
        public readonly bool $skipLinear = false,
    ) {
    }

    public function gitProbeRequired(): bool
    {
        return $this->probeGit && ($this->includeGithub || $this->includeGitlab);
    }

    public function probesTracker(IssueTrackerProvider $tracker): bool
    {
        return match ($tracker) {
            IssueTrackerProvider::Jira => $this->probeJira,
            IssueTrackerProvider::Linear => $this->probeLinear,
            IssueTrackerProvider::Auto => false,
        };
    }

    public function waivesSelectedTrackerProbe(IssueTrackerProvider $tracker): bool
    {
        return match ($tracker) {
            IssueTrackerProvider::Jira => $this->skipJira,
            IssueTrackerProvider::Linear => $this->skipLinear,
            IssueTrackerProvider::Auto => false,
        };
    }
}
