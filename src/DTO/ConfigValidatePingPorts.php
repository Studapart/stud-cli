<?php

declare(strict_types=1);

namespace App\DTO;

use App\Service\GitHostingReadPort;
use App\Service\IssueTrackerReadPort;

/**
 * Injected connectivity ports for config:validate pings (SCI-218). Pings only read.
 */
final class ConfigValidatePingPorts
{
    public function __construct(
        public readonly ?IssueTrackerReadPort $jiraIssueTracker,
        public readonly ?IssueTrackerReadPort $linearIssueTracker,
        public readonly ?GitHostingReadPort $projectGitHosting,
        public readonly ?GitHostingReadPort $githubSmokeHosting,
        public readonly ?GitHostingReadPort $gitlabSmokeHosting,
        public readonly ?MessageRef $jiraSetupError = null,
        public readonly ?MessageRef $linearSetupError = null,
    ) {
    }
}
