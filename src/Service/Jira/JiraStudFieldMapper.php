<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Service\StudIssueKeys;

/**
 * Maps stud-neutral issue write bags to Jira REST field bags.
 */
final class JiraStudFieldMapper
{
    /** @var array<string, string> */
    private const STUD_TO_JIRA = [
        StudIssueKeys::TITLE => JiraIssueFieldKeys::SUMMARY,
        StudIssueKeys::ISSUE_TYPE => JiraIssueFieldKeys::ISSUE_TYPE,
    ];

    /**
     * @param array<string, mixed> $studFields
     *
     * @return array<string, mixed>
     */
    public function toJiraCreateOrUpdateFields(array $studFields): array
    {
        $jiraFields = [];

        foreach ($studFields as $key => $value) {
            $jiraKey = self::STUD_TO_JIRA[$key] ?? $key;
            $jiraFields[$jiraKey] = $this->mapNestedValue($key, $value);
        }

        return $jiraFields;
    }

    private function mapNestedValue(string $studKey, mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($studKey !== StudIssueKeys::ISSUE_TYPE) {
            return $value;
        }

        $mapped = [];
        foreach ($value as $nestedKey => $nestedValue) {
            $mappedKey = match ($nestedKey) {
                StudIssueKeys::ID => JiraIssueFieldKeys::ID,
                StudIssueKeys::NAME => JiraIssueFieldKeys::NAME,
                StudIssueKeys::KEY => JiraIssueFieldKeys::KEY,
                StudIssueKeys::ACCOUNT_ID => JiraIssueFieldKeys::ACCOUNT_ID,
                default => $nestedKey,
            };
            $mapped[$mappedKey] = $nestedValue;
        }

        return $mapped;
    }
}
