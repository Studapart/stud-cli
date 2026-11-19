<?php

declare(strict_types=1);

namespace App\Service;

final class IssueTrackerClients
{
    public function __construct(
        public readonly ?JiraApiClient $jira,
        public readonly ?JiraAttachmentService $jiraAttachments,
        public readonly ?LinearApiClient $linear = null,
        public readonly ?LinearAttachmentService $linearAttachments = null,
    ) {
    }
}
