<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\Service\Prompt\PromptInterface;

class ItemCreatePromptService
{
    public function __construct(
        private readonly JiraApiClient $jiraService,
        private readonly IssueFieldResolver $fieldResolver,
        private readonly PromptInterface $prompt,
    ) {
    }

    /**
     * @param list<string> $extraRequiredFieldIds
     * @param array<string, mixed>|null $descriptionAdf
     *
     * @return array<string, mixed>|null
     */
    public function promptForExtraRequiredFields(
        bool $interactive,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        string $summary,
        ?array $descriptionAdf,
        array $extraRequiredFieldIds,
    ): ?array {
        if (! $interactive) {
            return null;
        }

        try {
            $allFields = $this->jiraService->getCreateMetaFields($projectKey, $issueTypeId);
        } catch (\Throwable) {
            return null;
        }
        $result = [];
        foreach ($extraRequiredFieldIds as $fieldId) {
            $fieldId = (string) $fieldId;
            $name = (string) ($allFields[$fieldId]['name'] ?? $fieldId);
            $value = $this->getPromptedValueForExtraField(
                $fieldId,
                $name,
                $projectKey,
                $issueTypeId,
                $typeExplicitlyProvided,
                $summary,
                $descriptionAdf,
                $allFields,
            );
            if ($value !== null) {
                $result += $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $allFields
     * @param array<string, mixed>|null $descriptionAdf
     *
     * @return array<string, mixed>|null
     */
    public function getPromptedValueForExtraField(
        string $fieldId,
        string $name,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        string $summary,
        ?array $descriptionAdf,
        array $allFields,
    ): ?array {
        $standardKind = $this->fieldResolver->extraFieldStandardKind(strtolower($fieldId), strtolower($name));
        if ($standardKind !== null) {
            return $this->getValueForStandardExtraField(
                $standardKind,
                $fieldId,
                $projectKey,
                $issueTypeId,
                $typeExplicitlyProvided,
                $summary,
                $descriptionAdf,
                $allFields,
            );
        }
        $value = $this->prompt->ask(MessageRef::key('item.create.prompt_custom_field', ['name' => $name]));
        if ($value === null || trim($value) === '') {
            return null;
        }

        return [$fieldId => trim($value)];
    }

    /**
     * @param array<string, mixed> $allFields
     * @param array<string, mixed>|null $descriptionAdf
     *
     * @return array<string, mixed>|null
     */
    public function getValueForStandardExtraField(
        string $kind,
        string $fieldId,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        string $summary,
        ?array $descriptionAdf,
        array $allFields,
    ): ?array {
        return match ($kind) {
            'project' => [$fieldId => ['key' => $projectKey]],
            'reporter' => [$fieldId => ['accountId' => $this->jiraService->getCurrentUserAccountId()]],
            'assignee' => [$fieldId => ['accountId' => $this->jiraService->getCurrentUserAccountId()]],
            'issuetype' => $this->promptIssueTypeValue($projectKey, $issueTypeId, $typeExplicitlyProvided, $allFields),
            'summary' => [$fieldId => $summary],
            'description' => ($v = $this->promptDescriptionValue($descriptionAdf)) !== null ? [$fieldId => $v] : null,
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
        $value = ['id' => $chosenId];
        $result = [];
        foreach ($allFields as $fid => $meta) {
            $n = strtolower((string) ($meta['name'] ?? ''));
            if ($n === 'issue type' || $n === 'issuetype') {
                $result[(string) $fid] = $value;
            }
        }

        return $result;
    }

    public function chooseIssueTypeInteractively(string $projectKey): ?string
    {
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
        if ($answer === null || trim($answer) === '') {
            return null;
        }

        return $this->jiraService->plainTextToDescriptionAdf(trim($answer));
    }
}
