<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\GitHostingPort;
use App\Service\IssueTrackerPort;

/**
 * Injected connectivity ports for config:validate pings (SCI-218).
 */
final class ConfigValidatePingPorts
{
    public function __construct(
        public readonly ?IssueTrackerPort $jiraIssueTracker,
        public readonly ?IssueTrackerPort $linearIssueTracker,
        public readonly ?GitHostingPort $projectGitHosting,
        public readonly ?GitHostingPort $githubSmokeHosting,
        public readonly ?GitHostingPort $gitlabSmokeHosting,
        public readonly ?MessageRef $jiraSetupError = null,
        public readonly ?MessageRef $linearSetupError = null,
    ) {
    }
}
