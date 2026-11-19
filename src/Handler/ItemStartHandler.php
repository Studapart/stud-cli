<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\DTO\WorkItem;
use App\Enum\WorkflowChannel;
use App\Exception\ApiException;
use App\Exception\GitException;
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

        $fetchFailure = $this->openBranch($recorder, $issue, $key, $projectConfig);
        if ($fetchFailure instanceof WorkflowResponse) {
            return $fetchFailure;
        }

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
    private function openBranch(WorkflowRecorder $recorder, WorkItem $issue, string $key, array $projectConfig): ?WorkflowResponse
    {
        $branchName = $this->planner->branchName($issue, $key, $this->workflows->branchPrefix($recorder, $issue, $projectConfig));
        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('item.start.generated_branch', ['branch' => $branchName]), WorkflowChannel::Git);
        $action = $this->planner->resolveAction($branchName, $this->branches->findBranches($key));
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('item.start.fetching_changes'), WorkflowChannel::Git);

        try {
            $this->branches->fetch();
        } catch (GitException $e) {
            return $this->recoverFromFetchFailure($recorder, $action, $branchName, $e);
        }

        $action = $this->planner->resolveAction($branchName, $this->branches->findBranches($key));
        $this->branches->applyStartAction($recorder, $action, $branchName);

        return null;
    }

    /**
     * @param array{action: string, branch: string} $action
     */
    private function recoverFromFetchFailure(
        WorkflowRecorder $recorder,
        array $action,
        string $branchName,
        GitException $e,
    ): ?WorkflowResponse {
        if ($action['action'] === BranchAction::SWITCH_LOCAL) {
            $recorder->addWarning(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('item.start.warning_fetch_local', ['error' => $e->getTechnicalDetails()]),
            );
            $this->branches->applyStartAction($recorder, $action, $branchName);

            return null;
        }

        $recorder->addErrorWithDetails(
            WorkflowEntryRecorder::VERBOSITY_NORMAL,
            MessageRef::key('item.start.error_fetch'),
            $e->getTechnicalDetails(),
        );

        return $recorder->toResponse(1);
    }
}
