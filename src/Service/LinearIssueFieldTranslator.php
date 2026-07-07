<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\Exception\StudConfigException;
use App\Service\Linear\LinearIssueMutationKeys;

/**
 * Translates stud-neutral handler field bags into Linear GraphQL mutation inputs.
 */
class LinearIssueFieldTranslator
{
    /**
     * @return array<string, array{required: bool, name: string}>
     */
    public function linearFieldMeta(): array
    {
        return [
            StudIssueKeys::LABELS => ['required' => false, 'name' => 'Labels'],
            StudIssueKeys::PRIORITY => ['required' => false, 'name' => 'Priority'],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $projectConfig
     *
     * @return array{teamId: string, title: string, description: ?string, labelIds: list<string>, priority: ?int, parentId: ?string}
     */
    public function toCreateInput(array $fields, LinearApiClient $client, array $projectConfig = []): array
    {
        $teamKey = $this->resolveTeamKey($fields);
        $teamId = $client->resolveTeamId($teamKey);

        $labelNames = $this->resolveLabelNames($fields);
        $typeName = $this->resolveIssueTypeName($fields);
        $typeGroupId = $this->readTypeGroupId($projectConfig);

        $labelIds = $client->resolveLabelIds($teamKey, $labelNames, null);
        if ($typeName !== null && $typeName !== '') {
            $typeLabelResolver = new LinearTypeLabelResolver($client);
            if ($typeGroupId !== null) {
                $typeLabelIds = [$typeLabelResolver->resolveTypeLabelId($typeName, $projectConfig, $teamKey)];
            } else {
                $typeLabelIds = $client->resolveLabelIds($teamKey, [$typeName], null);
            }
            $labelIds = array_values(array_unique(array_merge($labelIds, $typeLabelIds)));
        }

        return [
            LinearIssueMutationKeys::TEAM_ID => $teamId,
            LinearIssueMutationKeys::TITLE => (string) ($fields[StudIssueKeys::TITLE] ?? ''),
            LinearIssueMutationKeys::DESCRIPTION => $this->extractDescription($fields[StudIssueKeys::DESCRIPTION] ?? null),
            LinearIssueMutationKeys::LABEL_IDS => $labelIds,
            LinearIssueMutationKeys::PRIORITY => $this->resolvePriorityValue($fields[StudIssueKeys::PRIORITY] ?? null),
            LinearIssueMutationKeys::PARENT_ID => $this->resolveParentId($fields[StudIssueKeys::PARENT] ?? null, $client),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{title?: string, description?: ?string, labelIds?: list<string>, priority?: ?int}
     */
    public function toUpdateInput(array $fields, LinearApiClient $client, string $issueKey, ?string $typeGroupId = null): array
    {
        $input = [];

        if (isset($fields[StudIssueKeys::TITLE]) && is_string($fields[StudIssueKeys::TITLE]) && $fields[StudIssueKeys::TITLE] !== '') {
            $input[LinearIssueMutationKeys::TITLE] = $fields[StudIssueKeys::TITLE];
        }

        if (array_key_exists(StudIssueKeys::DESCRIPTION, $fields)) {
            $input[LinearIssueMutationKeys::DESCRIPTION] = $this->extractDescription($fields[StudIssueKeys::DESCRIPTION]);
        }

        if (isset($fields[StudIssueKeys::LABELS])) {
            $teamKey = $client->resolveTeamKeyFromIssue($issueKey);
            $input[LinearIssueMutationKeys::LABEL_IDS] = $client->resolveLabelIds(
                $teamKey,
                $this->normalizeLabelNames($fields[StudIssueKeys::LABELS]),
                $typeGroupId,
            );
        }

        if (array_key_exists(StudIssueKeys::PRIORITY, $fields)) {
            $input[LinearIssueMutationKeys::PRIORITY] = $this->resolvePriorityValue($fields[StudIssueKeys::PRIORITY]);
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    protected function readTypeGroupId(array $projectConfig): ?string
    {
        $value = $projectConfig[ProjectStudConfigKeys::LINEAR_TYPE_LABEL_GROUP_ID] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{type: string, version: int, content: list<array<string, mixed>>}
     */
    public function formatDescriptionPayload(string $text): array
    {
        return [
            'type' => 'doc',
            'version' => 1,
            'content' => [
                ['type' => 'linearMarkdown', 'markdown' => $text],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function resolveTeamKey(array $fields): string
    {
        $project = $fields[StudIssueKeys::PROJECT] ?? null;
        if (is_array($project) && isset($project[StudIssueKeys::KEY]) && is_string($project[StudIssueKeys::KEY]) && $project[StudIssueKeys::KEY] !== '') {
            return $project[StudIssueKeys::KEY];
        }

        throw StudConfigException::linearTeamKeyRequired();
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function resolveIssueTypeName(array $fields): ?string
    {
        $issueType = $fields[StudIssueKeys::ISSUE_TYPE] ?? null;
        if (! is_array($issueType)) {
            return null;
        }

        $name = $issueType[StudIssueKeys::NAME] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    protected function resolveLabelNames(array $fields): array
    {
        if (! isset($fields[StudIssueKeys::LABELS])) {
            return [];
        }

        return $this->normalizeLabelNames($fields[StudIssueKeys::LABELS]);
    }

    /**
     * @return list<string>
     */
    protected function normalizeLabelNames(mixed $labels): array
    {
        if (is_string($labels) && $labels !== '') {
            return [$labels];
        }

        if (! is_array($labels)) {
            return [];
        }

        $names = [];
        foreach ($labels as $label) {
            if (is_string($label) && $label !== '') {
                $names[] = $label;
            }
        }

        return $names;
    }

    protected function resolvePriorityValue(mixed $priority): ?int
    {
        if ($priority === null) {
            return null;
        }

        if (is_array($priority)) {
            $name = $priority[StudIssueKeys::NAME] ?? null;
            if (is_string($name) && $name !== '') {
                return LinearIssueMapper::priorityNameToValue($name);
            }

            return null;
        }

        if (is_string($priority)) {
            return LinearIssueMapper::priorityNameToValue($priority);
        }

        if (is_int($priority)) {
            return $priority === 0 ? null : $priority;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $parent
     */
    protected function resolveParentId(?array $parent, LinearApiClient $client): ?string
    {
        if ($parent === null) {
            return null;
        }

        $key = $parent[StudIssueKeys::KEY] ?? null;
        if (! is_string($key) || $key === '') {
            return null;
        }

        return $client->resolveIssueId($key);
    }

    protected function extractDescription(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }

        if (is_string($description)) {
            return $description;
        }

        if (! is_array($description)) {
            return null;
        }

        return $this->extractDescriptionFromAdf($description);
    }

    /**
     * @param array<string, mixed> $adf
     */
    protected function extractDescriptionFromAdf(array $adf): ?string
    {
        $content = $adf['content'] ?? null;
        if (! is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'linearMarkdown' && is_string($block['markdown'] ?? null)) {
                return $block['markdown'];
            }
        }

        $text = $this->flattenAdfText($content);

        return $text !== '' ? $text : null;
    }

    /**
     * @param array<mixed> $nodes
     */
    protected function flattenAdfText(array $nodes): string
    {
        $parts = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? '') === 'text' && is_string($node['text'] ?? null)) {
                $parts[] = $node['text'];
            }

            $nested = $node['content'] ?? null;
            if (is_array($nested)) {
                $nestedText = $this->flattenAdfText($nested);
                if ($nestedText !== '') {
                    $parts[] = $nestedText;
                }
            }
        }

        return trim(implode("\n", $parts));
    }
}
