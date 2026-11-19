<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ExtraFieldPromptContext;
use App\DTO\MessageRef;
use App\Service\Prompt\PromptInterface;

class ItemCreatePromptService
{
    public function __construct(
        private readonly ?JiraApiClient $jiraService,
        private readonly IssueFieldResolver $fieldResolver,
        private readonly PromptInterface $prompt,
    ) {
    }

    /**
     * @param list<string> $extraRequiredFieldIds
     *
     * @return array<string, mixed>|null
     */
    public function promptForExtraRequiredFields(
        bool $interactive,
        ExtraFieldPromptContext $context,
        array $extraRequiredFieldIds,
    ): ?array {
        if (! $interactive || $this->jiraService === null) {
            return null;
        }

        try {
            $allFields = $this->jiraService->getCreateMetaFields($context->state->projectKey, $context->state->issueTypeId);
        } catch (\Throwable) {
            return null;
        }
        $result = [];
        foreach ($extraRequiredFieldIds as $fieldId) {
            $fieldId = (string) $fieldId;
            $name = (string) ($allFields[$fieldId]['name'] ?? $fieldId);
            $value = $this->getPromptedValueForExtraField($fieldId, $name, $context, $allFields);
            if ($value !== null) {
                $result += $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $allFields
     *
     * @return array<string, mixed>|null
     */
    public function getPromptedValueForExtraField(
        string $fieldId,
        string $name,
        ExtraFieldPromptContext $context,
        array $allFields,
    ): ?array {
        $standardKind = $this->fieldResolver->extraFieldStandardKind(strtolower($fieldId), strtolower($name));
        if ($standardKind !== null) {
            return $this->getValueForStandardExtraField($standardKind, $context, $allFields);
        }
        $value = $this->prompt->ask(MessageRef::key('item.create.prompt_custom_field', ['name' => $name]));
        if ($value === null || trim($value) === '') {
            return null;
        }

        return [$fieldId => trim($value)];
    }

    /**
     * @param array<string, mixed> $allFields
     *
     * @return array<string, mixed>|null
     */
    public function getValueForStandardExtraField(string $kind, ExtraFieldPromptContext $context, array $allFields): ?array
    {
        $state = $context->state;

        return match ($kind) {
            StudIssueKeys::PROJECT => [StudIssueKeys::PROJECT => [StudIssueKeys::KEY => $state->projectKey]],
            StudIssueKeys::REPORTER => $this->accountField(StudIssueKeys::REPORTER),
            StudIssueKeys::ASSIGNEE => $this->accountField(StudIssueKeys::ASSIGNEE),
            StudIssueKeys::ISSUE_TYPE => $this->promptIssueTypeValue($state->projectKey, $state->issueTypeId, $context->typeExplicitlyProvided, $allFields),
            StudIssueKeys::TITLE => [StudIssueKeys::TITLE => (string) $state->fields[StudIssueKeys::TITLE]],
            StudIssueKeys::DESCRIPTION => ($v = $this->promptDescriptionValue($context->descriptionAdf)) !== null ? [StudIssueKeys::DESCRIPTION => $v] : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $allFields
     *
     * @return array<string, mixed>|null
     */
    public function promptIssueTypeValue(
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        array $allFields,
    ): ?array {
        $chosenId = $typeExplicitlyProvided ? $issueTypeId : $this->chooseIssueTypeInteractively($projectKey);
        if ($chosenId === null) {
            return null;
        }

        return [StudIssueKeys::ISSUE_TYPE => [StudIssueKeys::ID => $chosenId]];
    }

    public function chooseIssueTypeInteractively(string $projectKey): ?string
    {
        if ($this->jiraService === null) {
            return null;
        }
        $issueTypes = $this->jiraService->getCreateMetaIssueTypes($projectKey);
        if ($issueTypes === []) {
            return null;
        }
        $choices = array_column($issueTypes, 'name');
        $selectedName = $this->prompt->choice(MessageRef::key('item.create.prompt_issue_type_choice'), $choices);
        foreach ($issueTypes as $it) {
            if ($it['name'] === $selectedName) {
                return $it['id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $descriptionAdf
     *
     * @return array<string, mixed>|string|null
     */
    public function promptDescriptionValue(?array $descriptionAdf): array|string|null
    {
        if ($descriptionAdf !== null) {
            return $descriptionAdf;
        }
        $answer = $this->prompt->ask(MessageRef::key('item.create.prompt_description_required'));
        if ($answer === null || trim($answer) === '' || $this->jiraService === null) {
            return null;
        }

        return $this->jiraService->plainTextToDescriptionAdf(trim($answer));
    }

    /**
     * Builds the account payload for reporter or assignee. Null when Jira is not configured.
     *
     * @return array<string, mixed>|null
     */
    protected function accountField(string $field): ?array
    {
        if ($this->jiraService === null) {
            return null;
        }

        return [$field => [StudIssueKeys::ACCOUNT_ID => $this->jiraService->getCurrentUserAccountId()]];
    }
}
