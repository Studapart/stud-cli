<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Exception\ApiException;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\Capability\WorkItemLinearAware;
use App\Response\ProjectsLabelsResponse;
use App\Service\IssueTrackerResolver;
use App\Service\LinearMetadataClient;

/**
 * Lists Linear LabelGroups and child labels for a team key.
 *
 * Jira: returns empty groups with a notice (labels discovery deferred).
 */
class ProjectsLabelsHandler implements WorkItemJiraAware, WorkItemLinearAware
{
    public function __construct(
        private readonly ?LinearMetadataClient $linearClient,
        private readonly IssueTrackerResolver $providerResolver,
        /** @var array<string, mixed> */
        private readonly array $globalConfig,
        /** @var array<string, mixed> */
        private readonly array $projectConfig,
    ) {
    }

    public function handle(string $projectKey, bool $groupsOnly): ProjectsLabelsResponse
    {
        $resolution = $this->providerResolver->resolveActiveProvider($this->globalConfig, $this->projectConfig);
        if (! $resolution['ok']) {
            return ProjectsLabelsResponse::error($resolution['error']);
        }

        if ($resolution['provider'] === 'jira') {
            return ProjectsLabelsResponse::success([], [
                ResponseMessage::notice(MessageRef::key('project.labels.labels_not_supported_for_jira')),
            ]);
        }

        try {
            $groups = $this->fetchLinearLabelGroups($projectKey, $groupsOnly);
        } catch (\Throwable $e) {
            return ProjectsLabelsResponse::error($e->getMessage());
        }

        if ($groups === []) {
            return ProjectsLabelsResponse::success([], [
                ResponseMessage::warning(MessageRef::key('project.labels.no_label_groups', ['project' => $projectKey])),
            ]);
        }

        return ProjectsLabelsResponse::success($groups);
    }

    /**
     * @return list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}>
     */
    protected function fetchLinearLabelGroups(string $projectKey, bool $groupsOnly): array
    {
        if ($this->linearClient === null) {
            throw new ApiException('Linear is not configured.', 'LINEAR_API_KEY is missing from global config.');
        }

        return $this->linearClient->getTeamLabelGroups($projectKey, $groupsOnly);
    }
}
