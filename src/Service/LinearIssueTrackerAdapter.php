<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\DTO\Project;
use App\DTO\StateChange;
use App\DTO\WorkItem;

/**
 * Linear adapter for IssueTrackerPort (SCI-164+ implements full delegation).
 */
class LinearIssueTrackerAdapter implements IssueTrackerPort, IssueTrackerLabelGroupsCapable
{
    public function __construct(
        private readonly LinearApiClient $linearApiClient,
        private readonly LinearIssueFieldTranslator $fieldTranslator = new LinearIssueFieldTranslator(),
        private readonly LinearIssueMapper $issueMapper = new LinearIssueMapper(),
        private readonly ?GitRepository $gitRepository = null,
    ) {
    }

    public function getIssue(string $key, bool $renderFields = false): WorkItem
    {
        unset($renderFields);

        return $this->issueMapper->mapToWorkItem(
            $this->linearApiClient->getIssue($key),
            $this->readTypeGroupId(),
        );
    }

    public function search(string $query): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listAssignedActive(?string $projectKey = null, bool $onlyMine = true): array
    {
        $nodes = $this->linearApiClient->listAssignedActiveIssues($projectKey, $onlyMine);
        $typeGroupId = $this->readTypeGroupId();
        $issues = [];
        foreach ($nodes as $node) {
            $issues[] = $this->issueMapper->mapToWorkItem($node, $typeGroupId);
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{key: string, self: string}
     */
    public function create(array $input): array
    {
        $mutationInput = $this->fieldTranslator->toCreateInput(
            $input,
            $this->linearApiClient,
            $this->readTypeGroupId(),
        );
        $issue = $this->linearApiClient->issueCreate($mutationInput);
        $mapped = $this->issueMapper->mapCreateResponse($issue);

        return [
            'key' => $mapped['identifier'],
            'self' => $mapped['url'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $key, array $input): void
    {
        $issueId = $this->linearApiClient->resolveIssueId($key);
        $mutationInput = $this->fieldTranslator->toUpdateInput(
            $input,
            $this->linearApiClient,
            $key,
            $this->readTypeGroupId(),
        );
        $this->linearApiClient->issueUpdate($issueId, $mutationInput);
    }

    /**
     * @return array<string, array{required: bool, name: string}>
     */
    public function getCreateMetaFields(string $projectKey, string $issueTypeId): array
    {
        unset($projectKey, $issueTypeId);

        return $this->fieldTranslator->linearFieldMeta();
    }

    /**
     * @return array<string, array{required: bool, name: string}>
     */
    public function getEditMetaFields(string $key): array
    {
        unset($key);

        return $this->fieldTranslator->linearFieldMeta();
    }

    /**
     * @return array{type: string, version: int, content: list<array<string, mixed>>}
     */
    public function formatDescription(string $text, string $format = 'plain'): array
    {
        unset($format);

        return $this->fieldTranslator->formatDescriptionPayload($text);
    }

    public function listProjectStateChanges(string $projectKey): array
    {
        $states = $this->linearApiClient->getTeamWorkflowStates($projectKey);

        return array_map(
            static fn (array $state): StateChange => new StateChange(
                id: (string) $state['id'],
                name: (string) $state['name'],
                type: (string) $state['type'],
            ),
            $states,
        );
    }

    public function listItemStateChanges(string $itemKey): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function applyStateChange(string $itemKey, string $changeId): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function assign(string $key, ?string $user = null): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listTeams(): array
    {
        $teams = [];
        foreach ($this->linearApiClient->listTeams() as $team) {
            $teams[] = new Project($team['key'], $team['name']);
        }

        return $teams;
    }

    public function listFiltersOrViews(): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function runFilterOrView(string $name): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listWorkflowMetadata(?string $projectKey = null): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listTypeLabels(?string $projectKey = null): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listLabelGroups(string $projectKey, bool $groupsOnly = false): array
    {
        return $this->linearApiClient->getTeamLabelGroups($projectKey, $groupsOnly);
    }

    public function ping(): void
    {
        $this->linearApiClient->ping();
    }

    public function listAttachments(string $key): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function uploadAttachment(string $key, string $localPath): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function downloadAttachment(string $url, string $destPath): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    protected function readTypeGroupId(): ?string
    {
        if ($this->gitRepository === null) {
            return null;
        }

        $config = $this->gitRepository->readProjectConfig();
        $value = $config[ProjectStudConfigKeys::LINEAR_TYPE_LABEL_GROUP_ID] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
