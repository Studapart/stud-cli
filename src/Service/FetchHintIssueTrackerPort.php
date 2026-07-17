<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\WorkItem;
use App\Exception\ApiException;

/**
 * Delegates to an inner port and attaches resolution hints on getIssue failures.
 */
class FetchHintIssueTrackerPort implements IssueTrackerPort
{
    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function __construct(
        private readonly IssueTrackerPort $inner,
        private readonly IssueTrackerFetchFailureHintBuilder $hintBuilder,
        private readonly string $attemptedProvider,
        private readonly ?string $cliOverride,
        private readonly array $globalConfig,
        private readonly array $projectConfig,
    ) {
    }

    public function getIssue(string $key, bool $renderFields = false): WorkItem
    {
        try {
            return $this->inner->getIssue($key, $renderFields);
        } catch (ApiException $e) {
            $hint = $this->hintBuilder->build(
                $key,
                $this->attemptedProvider,
                $this->cliOverride,
                $this->globalConfig,
                $this->projectConfig,
            );

            throw new ApiException(
                $e->getMessage(),
                $e->getTechnicalDetails(),
                $e->getStatusCode(),
                $e,
                $hint,
            );
        }
    }

    public function search(string $query): array
    {
        return $this->inner->search($query);
    }

    public function listAssignedActive(?string $projectKey = null, bool $onlyMine = true): array
    {
        return $this->inner->listAssignedActive($projectKey, $onlyMine);
    }

    public function create(array $input): array
    {
        return $this->inner->create($input);
    }

    public function update(string $key, array $input): void
    {
        $this->inner->update($key, $input);
    }

    public function getCreateMetaFields(string $projectKey, string $issueTypeId): array
    {
        return $this->inner->getCreateMetaFields($projectKey, $issueTypeId);
    }

    public function getEditMetaFields(string $key): array
    {
        return $this->inner->getEditMetaFields($key);
    }

    public function formatDescription(string $text, string $format = 'plain'): array
    {
        return $this->inner->formatDescription($text, $format);
    }

    public function listProjectStateChanges(string $projectKey): array
    {
        return $this->inner->listProjectStateChanges($projectKey);
    }

    public function listItemStateChanges(string $itemKey): array
    {
        return $this->inner->listItemStateChanges($itemKey);
    }

    public function applyStateChange(string $itemKey, string $changeId): void
    {
        $this->inner->applyStateChange($itemKey, $changeId);
    }

    public function assign(string $key, ?string $user = null): void
    {
        $this->inner->assign($key, $user);
    }

    public function listTeams(): array
    {
        return $this->inner->listTeams();
    }

    public function listFiltersOrViews(): array
    {
        return $this->inner->listFiltersOrViews();
    }

    public function runFilterOrView(string $name): array
    {
        return $this->inner->runFilterOrView($name);
    }

    public function ping(): void
    {
        $this->inner->ping();
    }

    public function listAttachments(string $key): array
    {
        return $this->inner->listAttachments($key);
    }

    public function uploadAttachment(string $key, string $localPath): void
    {
        $this->inner->uploadAttachment($key, $localPath);
    }

    public function downloadAttachment(string $url, string $destPath): void
    {
        $this->inner->downloadAttachment($url, $destPath);
    }
}
