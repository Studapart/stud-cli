<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;

class IssueFieldResolver
{
    public function __construct(
        private readonly JiraApiClient $jiraService,
        private readonly DurationParser $durationParser
    ) {
    }

    public function resolveIssueTypeName(?string $type, ?string $parentKey): string
    {
        if ($parentKey !== null && trim($parentKey) !== '') {
            return 'Sub-task';
        }

        return ($type !== null && trim((string) $type) !== '') ? trim((string) $type) : 'Story';
    }

    /**
     * @return string|null Issue type id or null if not found / API error
     */
    public function resolveIssueTypeId(string $projectKey, string $typeName): ?string
    {
        try {
            $issueTypes = $this->jiraService->getCreateMetaIssueTypes($projectKey);
        } catch (\Throwable) {
            return null;
        }
        $typeNameLower = strtolower($typeName);
        foreach ($issueTypes as $it) {
            if (strtolower($it['name']) === $typeNameLower) {
                return $it['id'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildBaseFields(
        string $projectKey,
        string $issueTypeId,
        string $summary,
        ?string $description,
        ?string $descriptionFormat,
        ?string $parentKey
    ): array {
        $fields = [
            IssueFieldBagKeys::PROJECT => [IssueFieldBagKeys::KEY => $projectKey],
            IssueFieldBagKeys::ISSUE_TYPE => [IssueFieldBagKeys::ID => $issueTypeId],
            IssueFieldBagKeys::SUMMARY => $summary,
        ];
        if ($description !== null && $description !== '') {
            $format = ($descriptionFormat !== null && trim($descriptionFormat) !== '') ? trim($descriptionFormat) : 'plain';
            $fields[IssueFieldBagKeys::DESCRIPTION] = $this->jiraService->descriptionToAdf($description, $format);
        }
        if ($parentKey !== null && trim($parentKey) !== '') {
            $fields[IssueFieldBagKeys::PARENT] = [IssueFieldBagKeys::KEY => trim($parentKey)];
        }

        return $fields;
    }

    /**
     * Fills standard fields (Project, Reporter, Assignee, Summary, Description, Issue Type, Parent) from metadata by name,
     * and returns the list of required field IDs that are not standard (i.e. need to be prompted or error).
     *
     * @param list<string> $requiredFieldIds
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @param array<string, mixed> $fields
     * @param array{projectKey: string, issueTypeId: string, summary: string, descriptionAdf: array<string, mixed>|null, assigneeOption: string|null, parentKey: string|null} $fieldValues
     * @return list<string>
     */
    public function resolveStandardFieldsAndExtraRequired(
        array $requiredFieldIds,
        array $allFieldsMeta,
        array &$fields,
        array $fieldValues,
        bool $fillIssueType
    ): array {
        $extraRequired = [];
        $issueTypeAddedToExtra = false;

        foreach ($requiredFieldIds as $fieldId) {
            $fieldId = (string) $fieldId;
            $name = (string) ($allFieldsMeta[$fieldId]['name'] ?? $fieldId);
            $nameLower = strtolower($name);

            if ($this->fillStandardFieldByName($nameLower, $fields, $fieldValues, $fillIssueType)) {
                continue;
            }
            if ($nameLower === 'issue type' || $nameLower === 'issuetype') {
                if (! $issueTypeAddedToExtra) {
                    $extraRequired[] = $fieldId;
                    $issueTypeAddedToExtra = true;
                }

                continue;
            }
            $extraRequired[] = $fieldId;
        }

        return $extraRequired;
    }

    /**
     * Fill one standard field by name into $fields. Returns true if field was filled.
     *
     * @param array<string, mixed> $fields
     * @param array{projectKey: string, issueTypeId: string, summary: string, descriptionAdf: array<string, mixed>|null, assigneeOption: string|null, parentKey: string|null} $fieldValues
     */
    protected function fillStandardFieldByName(string $nameLower, array &$fields, array $fieldValues, bool $fillIssueType): bool
    {
        $filled = match ($nameLower) {
            'project', 'reporter', 'assignee', 'summary' => true,
            'description' => $fieldValues['descriptionAdf'] !== null,
            'issue type', 'issuetype' => $fillIssueType,
            'parent' => $fieldValues['parentKey'] !== null,
            default => false,
        };
        if (! $filled) {
            return false;
        }
        match ($nameLower) {
            IssueFieldBagKeys::PROJECT => $fields[IssueFieldBagKeys::PROJECT] = [IssueFieldBagKeys::KEY => $fieldValues['projectKey']],
            IssueFieldBagKeys::REPORTER => $fields[IssueFieldBagKeys::REPORTER] = [IssueFieldBagKeys::ACCOUNT_ID => $this->jiraService->getCurrentUserAccountId()],
            IssueFieldBagKeys::ASSIGNEE => $fields[IssueFieldBagKeys::ASSIGNEE] = [IssueFieldBagKeys::ACCOUNT_ID => $fieldValues['assigneeOption'] ?? $this->jiraService->getCurrentUserAccountId()],
            IssueFieldBagKeys::SUMMARY => $fields[IssueFieldBagKeys::SUMMARY] = $fieldValues['summary'],
            IssueFieldBagKeys::DESCRIPTION => $fields[IssueFieldBagKeys::DESCRIPTION] = $fieldValues['descriptionAdf'] ?? [],
            'issue type', IssueFieldBagKeys::ISSUE_TYPE => $fields[IssueFieldBagKeys::ISSUE_TYPE] = [IssueFieldBagKeys::ID => $fieldValues['issueTypeId']],
            IssueFieldBagKeys::PARENT => $fields[IssueFieldBagKeys::PARENT] = [IssueFieldBagKeys::KEY => $fieldValues['parentKey'] ?? ''],
            // @codeCoverageIgnoreStart
            default => null,
            // @codeCoverageIgnoreEnd
        };

        return true;
    }

    /**
     * When the create screen has an Assignee field and we have no value yet, set assignee to --assignee or current user.
     *
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @param array<string, mixed> $fields
     */
    public function defaultAssigneeWhenFieldPresent(array $allFieldsMeta, array &$fields, ?string $assigneeOption): void
    {
        if (isset($fields[IssueFieldBagKeys::ASSIGNEE])) {
            return;
        }
        foreach ($allFieldsMeta as $meta) {
            $name = (string) $meta['name'];
            if (strtolower($name) === IssueFieldBagKeys::ASSIGNEE) {
                $accountId = $assigneeOption !== null
                    ? $assigneeOption
                    : $this->jiraService->getCurrentUserAccountId();
                $fields[IssueFieldBagKeys::ASSIGNEE] = [IssueFieldBagKeys::ACCOUNT_ID => $accountId];

                break;
            }
        }
    }

    /**
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @return list<string>
     */
    public function getRequiredFieldIdsFromMeta(array $allFieldsMeta): array
    {
        $required = [];
        foreach ($allFieldsMeta as $fieldId => $meta) {
            if ($meta['required']) {
                $required[] = $fieldId;
            }
        }

        return $required;
    }

    /**
     * Applies optional fields (labels, time original estimate) when present in createmeta and user supplied values.
     * Returns list of human-readable field names that were requested but skipped (not in createmeta or invalid format).
     *
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @param array<string, mixed> $fields Create payload; modified in place
     * @return list<MessageRef>
     */
    public function applyOptionalFieldsFromCreatemeta(
        array $allFieldsMeta,
        array &$fields,
        ?string $labelsOption,
        ?string $originalEstimateOption,
        mixed $translator = null
    ): array {
        $skipped = [];
        $labelsPayloadKey = $this->findOptionalFieldKey($allFieldsMeta, IssueFieldBagKeys::LABELS, IssueFieldBagKeys::LABELS);
        $estimatePayloadKey = $this->findOptionalFieldKey($allFieldsMeta, 'timeoriginalestimate', 'time original estimate');

        if ($labelsOption !== null && trim($labelsOption) !== '') {
            if ($labelsPayloadKey !== null) {
                $labels = array_values(array_filter(array_map('trim', explode(',', $labelsOption))));
                if ($labels !== []) {
                    $fields[IssueFieldBagKeys::LABELS] = $labels;
                }
            } else {
                $skipped[] = MessageRef::key('item.create.skipped_field_labels');
            }
        }

        if ($originalEstimateOption !== null && trim($originalEstimateOption) !== '') {
            $seconds = $this->durationParser->parseToSeconds(trim($originalEstimateOption));
            if ($seconds === null) {
                $skipped[] = MessageRef::key('item.create.skipped_field_original_estimate');
            } elseif ($estimatePayloadKey !== null) {
                $fields['timeoriginalestimate'] = $seconds;
            } else {
                $skipped[] = MessageRef::key('item.create.skipped_field_original_estimate');
            }
        }

        return $skipped;
    }

    /**
     * Finds createmeta field key by exact id or normalized name (for API payload key).
     *
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     */
    protected function findOptionalFieldKey(array $allFieldsMeta, string $fieldId, string $fieldNameNormalized): ?string
    {
        $nameLower = strtolower($fieldNameNormalized);
        foreach ($allFieldsMeta as $key => $meta) {
            $keyLower = strtolower((string) $key);
            $metaNameLower = strtolower($meta['name']);
            if ($keyLower === strtolower($fieldId) || $metaNameLower === $nameLower) {
                return $this->getCreatePayloadFieldKey((string) $key);
            }
        }

        return null;
    }

    /** @var array<string, array<string>> */
    private const EXTRA_FIELD_KIND_KEYS = [
        IssueFieldBagKeys::PROJECT => [IssueFieldBagKeys::PROJECT],
        IssueFieldBagKeys::REPORTER => [IssueFieldBagKeys::REPORTER],
        IssueFieldBagKeys::ASSIGNEE => [IssueFieldBagKeys::ASSIGNEE],
        IssueFieldBagKeys::ISSUE_TYPE => [IssueFieldBagKeys::ISSUE_TYPE, 'issue type'],
        IssueFieldBagKeys::SUMMARY => [IssueFieldBagKeys::SUMMARY],
        IssueFieldBagKeys::DESCRIPTION => [IssueFieldBagKeys::DESCRIPTION],
    ];

    public function extraFieldStandardKind(string $fieldIdLower, string $nameLower): ?string
    {
        foreach (self::EXTRA_FIELD_KIND_KEYS as $kind => $keys) {
            if (in_array($fieldIdLower, $keys, true) || in_array($nameLower, $keys, true)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Returns the field key to use in the create-issue payload.
     * Jira expects custom fields as "customfield_XXXXX"; createmeta may return numeric IDs.
     */
    public function getCreatePayloadFieldKey(string $fieldId): string
    {
        if (str_starts_with($fieldId, 'customfield_')) {
            return $fieldId;
        }
        if (preg_match('/^\d+$/', $fieldId)) {
            return 'customfield_' . $fieldId;
        }

        return $fieldId;
    }

    /**
     * Builds a human-readable list of extra required field names (and IDs) for error messages.
     *
     * @param list<string> $extraRequiredFieldIds
     */
    public function getExtraRequiredFieldsList(string $projectKey, string $issueTypeId, array $extraRequiredFieldIds): string
    {
        try {
            $allFields = $this->jiraService->getCreateMetaFields($projectKey, $issueTypeId);
        } catch (\Throwable) {
            return implode(', ', $extraRequiredFieldIds);
        }
        $parts = [];
        foreach ($extraRequiredFieldIds as $fieldId) {
            $fieldId = (string) $fieldId;
            $name = (string) ($allFields[$fieldId]['name'] ?? $fieldId);
            $payloadKey = $this->getCreatePayloadFieldKey($fieldId);
            $parts[] = $name . ' (' . $payloadKey . ')';
        }

        return implode(', ', $parts);
    }
}
