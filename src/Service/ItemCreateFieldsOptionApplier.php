<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\DTO\IssueCreationState;
use App\DTO\ItemCreateInput;

/**
 * Merges the item:create --fields option and the project work-item label default into the create payload.
 */
class ItemCreateFieldsOptionApplier
{
    /**
     * @param list<string> $defaultWorkItemLabels
     */
    public function __construct(
        private readonly FieldsParser $fieldsParser,
        private readonly array $defaultWorkItemLabels = [],
    ) {
    }

    /**
     * @return list<string> skipped optional fields that matched no create-meta field
     */
    public function apply(IssueCreationState $state, ItemCreateInput $input): array
    {
        $parsedFields = $input->fieldsMap ?? ($input->fieldsOption !== null ? $this->fieldsParser->parse($input->fieldsOption) : []);
        $parsedFields = $this->mergeWorkItemLabelDefault($parsedFields, $state);
        if ($parsedFields === []) {
            return [];
        }
        $state->fieldInputsForUpdate = $parsedFields;
        $result = $this->fieldsParser->matchAndTransform($parsedFields, $state->allFieldsMeta);
        foreach ($result['matched'] as $key => $value) {
            $state->fields[$key] = $value;
        }
        foreach ($result['inputByPayload'] as $payloadKey => $inputKey) {
            $state->payloadKeyToInputKey[$payloadKey] = $inputKey;
        }

        return $result['unmatched'];
    }

    /**
     * @param array<string, string|list<string>> $parsedFields
     * @return array<string, string|list<string>>
     */
    private function mergeWorkItemLabelDefault(array $parsedFields, IssueCreationState $state): array
    {
        $labelsKey = $this->findLabelsFieldKey($parsedFields);
        if ($labelsKey !== null) {
            if (ProjectStudConfigKeys::normalizeLabelList($parsedFields[$labelsKey]) === []) {
                unset($parsedFields[$labelsKey]);
            }

            return $parsedFields;
        }
        if ($this->defaultWorkItemLabels === [] || $this->creationStateHasLabels($state)) {
            return $parsedFields;
        }
        $parsedFields[StudIssueKeys::LABELS] = $this->defaultWorkItemLabels;

        return $parsedFields;
    }

    /**
     * @param array<string, mixed> $parsedFields
     */
    private function findLabelsFieldKey(array $parsedFields): ?string
    {
        foreach (array_keys($parsedFields) as $key) {
            if (strtolower((string) $key) === StudIssueKeys::LABELS) {
                return (string) $key;
            }
        }

        return null;
    }

    private function creationStateHasLabels(IssueCreationState $state): bool
    {
        $labelsKey = $this->findLabelsFieldKey($state->fields);

        return $labelsKey !== null
            && ProjectStudConfigKeys::normalizeLabelList($state->fields[$labelsKey]) !== [];
    }
}
