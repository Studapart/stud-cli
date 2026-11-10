<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\IssueFieldResolver;
use App\Service\ItemCreateFieldsOptionApplier;
use App\Service\Jira\JiraCreateDeferralCoordinator;
use App\Service\Jira\JiraIssueTrackerResolver;

final class ItemCreateFieldFlow
{
    public function __construct(
        public readonly IssueFieldResolver $fields,
        public readonly ItemCreateFieldsOptionApplier $options,
        public readonly JiraIssueTrackerResolver $jira,
        public readonly JiraCreateDeferralCoordinator $deferral,
    ) {
    }
}
