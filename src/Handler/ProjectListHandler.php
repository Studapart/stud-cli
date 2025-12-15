<?php

declare(strict_types=1);

namespace App\Handler;

use App\Response\ProjectListResponse;
use App\Service\JiraService;

class ProjectListHandler
{
    public function __construct(
        private readonly JiraService $jiraService
    ) {
    }

    public function handle(): ProjectListResponse
    {
        try {
            $projects = $this->jiraService->getProjects();

            return ProjectListResponse::success($projects);
        } catch (\Exception $e) {
            return ProjectListResponse::error($e->getMessage());
        }
    }
}
