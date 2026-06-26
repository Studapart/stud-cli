<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Guard\Capability\WorkItemJiraAware;
use App\Response\ProjectListResponse;
use App\Service\IssueTrackerPort;

class ProjectListHandler implements WorkItemJiraAware
{
    public function __construct(
        private readonly IssueTrackerPort $provider,
    ) {
    }

    public function handle(): ProjectListResponse
    {
        try {
            $projects = $this->provider->listTeams();

            return ProjectListResponse::success($projects);
        } catch (\Exception $e) {
            return ProjectListResponse::error(
                MessageRef::key('project.list.error_fetch', ['error' => $e->getMessage()])
            );
        }
    }
}
