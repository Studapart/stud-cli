<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Exception\ApiException;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\Capability\WorkItemLinearAware;
use App\Response\ProjectsWorkflowResponse;
use App\Service\IssueTrackerResolver;
use App\Service\JiraApiClient;
use App\Service\LinearMetadataClient;
use App\Service\ProjectsWorkflowNormalizer;

/**
 * Lists workflow transitions (Jira) or workflow states (Linear) for a project/team key.
 *
 * Jira discovery: locate a representative open issue via JQL, then call issue transitions.
 */
class ProjectsWorkflowHandler implements WorkItemJiraAware, WorkItemLinearAware
{
    public function __construct(
        private readonly ?JiraApiClient $jiraService,
        private readonly ?LinearMetadataClient $linearClient,
        private readonly IssueTrackerResolver $providerResolver,
        private readonly ProjectsWorkflowNormalizer $normalizer,
        /** @var array<string, mixed> */
        private readonly array $globalConfig,
        /** @var array<string, mixed> */
        private readonly array $projectConfig,
    ) {
    }

    public function handle(string $projectKey): ProjectsWorkflowResponse
    {
        $resolution = $this->providerResolver->resolveActiveProvider($this->globalConfig, $this->projectConfig);
        if (! $resolution['ok']) {
            return ProjectsWorkflowResponse::error($resolution['error']);
        }

        try {
            $stateChanges = $resolution['provider'] === 'linear'
                ? $this->fetchLinearStateChanges($projectKey)
                : $this->fetchJiraStateChanges($projectKey);
        } catch (\Throwable $e) {
            return ProjectsWorkflowResponse::error($e->getMessage());
        }

        if ($stateChanges === []) {
            return ProjectsWorkflowResponse::success([], [
                ResponseMessage::warning(MessageRef::key('project.workflow.no_state_changes', ['project' => $projectKey])),
            ]);
        }

        return ProjectsWorkflowResponse::success($stateChanges);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchJiraStateChanges(string $projectKey): array
    {
        if ($this->jiraService === null) {
            throw new ApiException('Jira is not configured.', 'JIRA credentials are missing from global config.');
        }

        $transitions = $this->jiraService->getProjectTransitions($projectKey);

        return $this->normalizer->fromJiraTransitions($transitions);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchLinearStateChanges(string $projectKey): array
    {
        if ($this->linearClient === null) {
            throw new ApiException('Linear is not configured.', 'LINEAR_API_KEY is missing from global config.');
        }

        $states = $this->linearClient->getTeamWorkflowStates($projectKey);

        return $this->normalizer->fromLinearStates($states);
    }
}
