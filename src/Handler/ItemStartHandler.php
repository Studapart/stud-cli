<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\DTO\WorkItem;
use App\Enum\WorkflowChannel;
use App\Exception\ApiException;
use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\ProjectBaseBranchAware;
use App\Response\WorkflowResponse;

class ItemStartHandler implements GitRepositoryAware, ProjectBaseBranchAware, JiraAware
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly ItemStartWorkflows $workflows,
        private readonly ItemStartBranchPlanner $planner,
        private readonly ItemBranchWorkspace $branches,
        private readonly array $jiraConfig,
    ) {
    }

    public function handle(string $key): WorkflowResponse
    {
        $recorder = new WorkflowRecorder();
        $key = strtoupper($key);
        $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.section', ['key' => $key]));

        $issue = $this->fetchIssue($recorder, $key);
        if ($issue instanceof WorkflowResponse) {
            return $issue;
        }

        $projectConfig = $this->branches->readProjectConfig();
        if (! empty($this->jiraConfig['JIRA_TRANSITION_ENABLED'])) {
            $this->workflows->transition($recorder, $key, $projectConfig);
        }

        $this->openBranch($recorder, $issue, $key, $projectConfig);

        return $recorder->toResponse(0);
    }

    private function fetchIssue(WorkflowEntryRecorder $recorder, string $key): WorkItem|WorkflowResponse
    {
        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.fetching', ['key' => $key]), WorkflowChannel::Jira);

        try {
            return $this->workflows->fetchIssue($key);
        } catch (ApiException $e) {
            $recorder->addErrorWithDetails(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                $e->getResolutionHint() ?? MessageRef::key('item.start.error_not_found', ['key' => $key]),
                $e->getTechnicalDetails()
            );

            return $recorder->toResponse(1);
        }
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function openBranch(WorkflowEntryRecorder $recorder, WorkItem $issue, string $key, array $projectConfig): void
    {
        $branchName = $this->planner->branchName($issue, $key, $this->workflows->branchPrefix($recorder, $issue, $projectConfig));
        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.generated_branch', ['branch' => $branchName]), WorkflowChannel::Git);
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.fetching_changes'), WorkflowChannel::Git);
        $this->branches->fetch();
        $action = $this->planner->resolveAction($branchName, $this->branches->findBranches($key));
        $this->branches->applyStartAction($recorder, $action, $branchName);
    }
}
