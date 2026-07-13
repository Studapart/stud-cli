<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\Project;
use App\DTO\ResponseMessage;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\ProjectListResponse;
use App\Service\IssueTrackerPortSupplier;
use App\Service\IssueTrackerProvidersQueryResolver;

class ProjectListHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        /** @var array<string, mixed> */
        private readonly array $globalConfig,
        /** @var array<string, mixed> */
        private readonly array $projectConfig,
        private readonly IssueTrackerProvidersQueryResolver $queryResolver = new IssueTrackerProvidersQueryResolver(),
    ) {
    }

    public function handle(?string $providerOverride = null): ProjectListResponse
    {
        $providers = $this->queryResolver->resolve($this->globalConfig, $this->projectConfig, $providerOverride);
        if ($providers === []) {
            $resolution = $this->portSupplier->resolve($this->globalConfig, $this->projectConfig);
            if (! $resolution['ok']) {
                return ProjectListResponse::error($resolution['error']);
            }

            $providers = [$resolution['provider']];
        }

        $projects = [];
        $projectProviders = [];
        $warnings = [];

        foreach ($providers as $providerSlug) {
            $resolution = $this->portSupplier->resolveForProvider($providerSlug, $this->globalConfig);
            if (! $resolution['ok']) {
                if (count($providers) === 1) {
                    return ProjectListResponse::error($resolution['error']);
                }
                $warnings[] = ResponseMessage::warning($resolution['error']);

                continue;
            }

            try {
                $fetched = $resolution['port']->listTeams();
            } catch (\Exception $e) {
                if (count($providers) === 1) {
                    return ProjectListResponse::error(
                        MessageRef::key('project.list.error_fetch', ['error' => $e->getMessage()])
                    );
                }
                $warnings[] = ResponseMessage::warning(
                    MessageRef::key('project.list.error_fetch', ['error' => $e->getMessage()])
                );

                continue;
            }

            foreach ($fetched as $project) {
                $projects[] = new Project($project->key, $project->name, $providerSlug);
                $projectProviders[] = $providerSlug;
            }
        }

        $multiProvider = count(array_unique($projectProviders)) > 1;

        return ProjectListResponse::success($projects, $projectProviders, $multiProvider, $warnings);
    }
}
