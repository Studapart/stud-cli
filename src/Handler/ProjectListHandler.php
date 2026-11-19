<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\Project;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\ProjectListResponse;
use App\Service\IssueTrackerProviderFanOut;
use App\Service\IssueTrackerReadPort;

class ProjectListHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly IssueTrackerProviderFanOut $fanOut,
    ) {
    }

    public function handle(?string $providerOverride = null): ProjectListResponse
    {
        $result = $this->fanOut->collect(
            $providerOverride,
            fn (IssueTrackerReadPort $port): array => $port->listTeams(),
            'project.list.error_fetch',
        );
        if ($result instanceof MessageRef) {
            return ProjectListResponse::error($result);
        }

        $projects = array_map(
            fn (Project $project, string $provider): Project => new Project($project->key, $project->name, $provider),
            $result->items,
            $result->providers,
        );

        return ProjectListResponse::success($projects, $result->providers, $result->isMultiProvider(), $result->warnings);
    }
}
