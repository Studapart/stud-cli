<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\DTO\Filter;
use App\DTO\Project;
use App\DTO\StateChange;
use App\DTO\WorkItem;
use App\Exception\ApiException;
use App\Service\Linear\LinearIssueMutationKeys;

/**
 * Linear adapter for IssueTrackerPort (SCI-164+ implements full delegation).
 */
class LinearIssueTrackerAdapter implements IssueTrackerPort, IssueTrackerLabelGroupsCapable
{
    private ?LinearAttachmentService $defaultAttachmentService = null;

    public function __construct(
        private readonly LinearApiClient $linearApiClient,
        private readonly LinearIssueFieldTranslator $fieldTranslator = new LinearIssueFieldTranslator(),
        private readonly LinearIssueMapper $issueMapper = new LinearIssueMapper(),
        private readonly ?GitRepository $gitRepository = null,
        private readonly ?LinearAttachmentService $linearAttachmentService = null,
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
        $nodes = $this->linearApiClient->searchIssues($query);
        $typeGroupId = $this->readTypeGroupId();
        $issues = [];
        foreach ($nodes as $node) {
            $issues[] = $this->issueMapper->mapToWorkItem($node, $typeGroupId);
        }

        return $issues;
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
            $this->readProjectConfig(),
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
        $teamKey = $this->linearApiClient->resolveTeamKeyFromIssue($itemKey);

        return array_map(
            static fn (array $state): StateChange => new StateChange(
                id: (string) $state['id'],
                name: (string) $state['name'],
                type: (string) $state['type'],
            ),
            $this->linearApiClient->getTeamWorkflowStates($teamKey),
        );
    }

    public function applyStateChange(string $itemKey, string $changeId): void
    {
        $issueId = $this->linearApiClient->resolveIssueId($itemKey);
        $this->linearApiClient->issueUpdate($issueId, [
            LinearIssueMutationKeys::STATE_ID => $changeId,
        ]);
    }

    public function assign(string $key, ?string $user = null): void
    {
        $this->linearApiClient->assignIssue($key, $user);
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
        $filters = [];
        foreach ($this->linearApiClient->listCustomViews() as $view) {
            $filters[] = new Filter($view['name'], $view['description']);
        }

        return $filters;
    }

    public function runFilterOrView(string $name): array
    {
        $view = $this->linearApiClient->resolveCustomViewByName($name);
        if ($view === null) {
            throw new ApiException(
                "Could not find Linear custom view \"{$name}\".",
                'No custom view matched the requested name.',
            );
        }

        $nodes = $this->linearApiClient->listIssuesByFilter($view['filterData']);
        $typeGroupId = $this->readTypeGroupId();
        $issues = [];
        foreach ($nodes as $node) {
            $issues[] = $this->issueMapper->mapToWorkItem($node, $typeGroupId);
        }

        return $issues;
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
        return $this->issueMapper->mapToWorkItem(
            $this->linearApiClient->getIssue($key),
            $this->readTypeGroupId(),
        )->attachments;
    }

    public function uploadAttachment(string $key, string $localPath): void
    {
        $this->attachmentService()->uploadFileToIssue($key, $localPath);
    }

    public function downloadAttachment(string $url, string $destPath): void
    {
        $content = $this->attachmentService()->downloadAttachmentContent($url);
        if (@file_put_contents($destPath, $content) === false) {
            throw new ApiException(
                'Could not write attachment to destination path.',
                sprintf('Failed to write file: %s', $destPath),
                500,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function readProjectConfig(): array
    {
        if ($this->gitRepository === null) {
            return [];
        }

        return $this->gitRepository->readProjectConfig();
    }

    protected function readTypeGroupId(): ?string
    {
        $config = $this->readProjectConfig();
        $value = $config[ProjectStudConfigKeys::LINEAR_TYPE_LABEL_GROUP_ID] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function attachmentService(): LinearAttachmentService
    {
        return $this->linearAttachmentService
            ?? ($this->defaultAttachmentService ??= new LinearAttachmentService($this->linearApiClient));
    }
}
