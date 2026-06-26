<?php

declare(strict_types=1);

namespace App\Handler;

use App\Guard\Capability\WorkItemJiraAware;
use App\Response\ProjectListResponse;
use App\Service\WorkItemProviderInterface;

class ProjectListHandler implements WorkItemJiraAware
{
    public function __construct(
        private readonly WorkItemProviderInterface $provider,
    ) {
    }

    public function handle(): ProjectListResponse
    {
        try {
            $projects = $this->provider->listTeams();

            return ProjectListResponse::success($projects);
        } catch (\Exception $e) {
            return ProjectListResponse::error($e->getMessage());
        }
    }
}
