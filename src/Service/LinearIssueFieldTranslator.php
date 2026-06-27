<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;

/**
 * Translates Jira-shaped handler field bags into Linear GraphQL mutation inputs.
 */
class LinearIssueFieldTranslator
{
    /**
     * @return array<string, array{required: bool, name: string}>
     */
    public function linearFieldMeta(): array
    {
        return [
            'labels' => ['required' => false, 'name' => 'Labels'],
            'priority' => ['required' => false, 'name' => 'Priority'],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{teamId: string, title: string, description: ?string, labelIds: list<string>, priority: ?int, parentId: ?string}
     */
    public function toCreateInput(array $fields, LinearApiClient $client, ?string $typeGroupId = null): array
    {
        $teamKey = $this->resolveTeamKey($fields);
        $teamId = $client->resolveTeamId($teamKey);

        $labelNames = $this->resolveLabelNames($fields);
        $typeName = $this->resolveIssueTypeName($fields);

        $labelIds = $client->resolveLabelIds($teamKey, $labelNames, null);
        if ($typeName !== null && $typeName !== '') {
            $typeLabelIds = $client->resolveLabelIds($teamKey, [$typeName], $typeGroupId);
            $labelIds = array_values(array_unique(array_merge($labelIds, $typeLabelIds)));
        }

        return [
            'teamId' => $teamId,
            'title' => (string) ($fields['summary'] ?? ''),
            'description' => $this->extractDescription($fields['description'] ?? null),
            'labelIds' => $labelIds,
            'priority' => $this->resolvePriorityValue($fields['priority'] ?? null),
            'parentId' => $this->resolveParentId($fields['parent'] ?? null, $client),
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

        if (isset($fields['summary']) && is_string($fields['summary']) && $fields['summary'] !== '') {
            $input['title'] = $fields['summary'];
        }

        if (array_key_exists('description', $fields)) {
            $input['description'] = $this->extractDescription($fields['description']);
        }

        if (isset($fields['labels'])) {
            $teamKey = $client->resolveTeamKeyFromIssue($issueKey);
            $input['labelIds'] = $client->resolveLabelIds(
                $teamKey,
                $this->normalizeLabelNames($fields['labels']),
                $typeGroupId,
            );
        }

        if (array_key_exists('priority', $fields)) {
            $input['priority'] = $this->resolvePriorityValue($fields['priority']);
        }

        return $input;
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
        $project = $fields['project'] ?? null;
        if (is_array($project) && isset($project['key']) && is_string($project['key']) && $project['key'] !== '') {
            return $project['key'];
        }

        throw new ApiException('Team key is required to create a Linear issue.', 'Missing project key in create payload.');
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function resolveIssueTypeName(array $fields): ?string
    {
        $issueType = $fields['issuetype'] ?? null;
        if (! is_array($issueType)) {
            return null;
        }

        $name = $issueType['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    protected function resolveLabelNames(array $fields): array
    {
        if (! isset($fields['labels'])) {
            return [];
        }

        return $this->normalizeLabelNames($fields['labels']);
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
            $name = $priority['name'] ?? null;
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

        $key = $parent['key'] ?? null;
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
