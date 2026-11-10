<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\ProjectStudConfigKeys;
use App\Contract\WorkflowEntryRecorder;
use App\DTO\WorkItem;
use App\Enum\IssueTrackerProvider;
use App\Service\GitRepository;

final class ItemStartWorkflows
{
    public function __construct(
        private readonly ItemTrackerGateway $tracker,
        private readonly GitRepository $gitRepository,
        private readonly JiraItemStartWorkflow $jira,
        private readonly LinearItemStartWorkflow $linear,
    ) {
    }

    public function fetchIssue(string $key): WorkItem
    {
        return $this->tracker->fetchIssue($key);
    }

    public function assign(WorkflowEntryRecorder $recorder, string $key, string $lineKey, string $warningKey): void
    {
        $this->tracker->assign($recorder, $key, $lineKey, $warningKey);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function transition(WorkflowEntryRecorder $recorder, string $key, array $projectConfig): void
    {
        $this->assign($recorder, $key, 'item.start.assigning', 'item.start.assign_error');
        $this->select($key, $projectConfig)->transition($recorder, $key, $projectConfig);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function branchPrefix(WorkflowEntryRecorder $recorder, WorkItem $issue, array $projectConfig): string
    {
        return $this->select($issue->key, $projectConfig)->branchPrefix($recorder, $issue, $projectConfig);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function usesLinear(string $issueKey, array $projectConfig): bool
    {
        $provider = ProjectStudConfigKeys::readIssueTrackerProvider($projectConfig);
        if (! is_string($provider)) {
            return false;
        }

        $normalized = IssueTrackerProvider::tryFromNormalized($provider);
        if ($normalized === IssueTrackerProvider::Linear) {
            return true;
        }

        if ($normalized !== IssueTrackerProvider::Auto) {
            return false;
        }

        return $this->issueKeyMatchesLinearTeam($issueKey, $projectConfig);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function issueKeyMatchesLinearTeam(string $issueKey, array $projectConfig): bool
    {
        $linearTeamKey = $projectConfig[ProjectStudConfigKeys::LINEAR_TEAM_KEY] ?? null;
        if (! is_string($linearTeamKey) || trim($linearTeamKey) === '') {
            return false;
        }

        $issueProjectKey = $this->gitRepository->getProjectKeyFromIssueKey($issueKey);

        return strtoupper($issueProjectKey) === strtoupper(trim($linearTeamKey));
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function select(string $issueKey, array $projectConfig): ItemStartWorkflow
    {
        return $this->usesLinear($issueKey, $projectConfig) ? $this->linear : $this->jira;
    }
}
