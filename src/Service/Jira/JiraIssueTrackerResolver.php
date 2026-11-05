<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Service\FetchHintIssueTrackerPort;
use App\Service\IssueTrackerPort;

final class JiraIssueTrackerResolver
{
    public function unwrap(IssueTrackerPort $port): IssueTrackerPort
    {
        while ($port instanceof FetchHintIssueTrackerPort) {
            $port = $port->getInner();
        }

        return $port;
    }

    public function isJiraBacked(IssueTrackerPort $port): bool
    {
        return $this->unwrap($port) instanceof JiraIssueTrackerCapable;
    }
}
