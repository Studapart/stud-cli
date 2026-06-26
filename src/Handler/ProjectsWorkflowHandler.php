<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Exception\ApiException;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\Capability\WorkItemLinearAware;
use App\Response\ProjectsWorkflowResponse;
use App\Service\IssueTrackerPortSupplier;
use App\Service\ProjectsWorkflowNormalizer;

/**
 * Lists workflow transitions (Jira) or workflow states (Linear) for a project/team key.
 */
class ProjectsWorkflowHandler implements WorkItemJiraAware, WorkItemLinearAware
{
    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        private readonly ProjectsWorkflowNormalizer $normalizer,
        /** @var array<string, mixed> */
        private readonly array $globalConfig,
        /** @var array<string, mixed> */
        private readonly array $projectConfig,
    ) {
    }

    public function handle(string $projectKey): ProjectsWorkflowResponse
    {
        $resolution = $this->portSupplier->resolve($this->globalConfig, $this->projectConfig);
        if (! $resolution['ok']) {
            return ProjectsWorkflowResponse::error($resolution['error']);
        }

        try {
            $stateChanges = $resolution['port']->listProjectStateChanges($projectKey);
        } catch (ApiException $e) {
            return ProjectsWorkflowResponse::error(
                MessageRef::key('project.workflow.error_fetch', ['error' => $e->getMessage()])
            );
        } catch (\Throwable $e) {
            return ProjectsWorkflowResponse::error(
                MessageRef::key('project.workflow.error_fetch', ['error' => $e->getMessage()])
            );
        }

        $mapped = $this->normalizer->fromStateChanges($stateChanges, $resolution['provider']);
        if ($mapped === []) {
            return ProjectsWorkflowResponse::success([], [
                ResponseMessage::warning(MessageRef::key('project.workflow.no_state_changes', ['project' => $projectKey])),
            ]);
        }

        return ProjectsWorkflowResponse::success($mapped);
    }
}
