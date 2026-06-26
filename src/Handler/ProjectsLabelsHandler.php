<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Exception\ApiException;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\Capability\WorkItemLinearAware;
use App\Response\ProjectsLabelsResponse;
use App\Service\IssueTrackerPortSupplier;

/**
 * Lists Linear LabelGroups and child labels for a team key.
 *
 * Jira: returns empty groups with a notice (labels discovery deferred).
 */
class ProjectsLabelsHandler implements WorkItemJiraAware, WorkItemLinearAware
{
    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        /** @var array<string, mixed> */
        private readonly array $globalConfig,
        /** @var array<string, mixed> */
        private readonly array $projectConfig,
    ) {
    }

    public function handle(string $projectKey, bool $groupsOnly): ProjectsLabelsResponse
    {
        $resolution = $this->portSupplier->resolve($this->globalConfig, $this->projectConfig);
        if (! $resolution['ok']) {
            return ProjectsLabelsResponse::error($resolution['error']);
        }

        if ($resolution['provider'] === 'jira') {
            return ProjectsLabelsResponse::success([], [
                ResponseMessage::notice(MessageRef::key('project.labels.labels_not_supported_for_jira')),
            ]);
        }

        try {
            $groups = $resolution['port']->listLabelGroups($projectKey, $groupsOnly);
        } catch (ApiException $e) {
            return ProjectsLabelsResponse::error(
                MessageRef::key('project.labels.error_fetch', ['error' => $e->getMessage()])
            );
        } catch (\Throwable $e) {
            return ProjectsLabelsResponse::error(
                MessageRef::key('project.labels.error_fetch', ['error' => $e->getMessage()])
            );
        }

        if ($groups === []) {
            return ProjectsLabelsResponse::success([], [
                ResponseMessage::warning(MessageRef::key('project.labels.no_label_groups', ['project' => $projectKey])),
            ]);
        }

        return ProjectsLabelsResponse::success($groups);
    }
}
