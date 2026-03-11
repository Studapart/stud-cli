<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Mutable state container for the Jira issue creation process.
 * Bundles the resolved metadata from createmeta API so that
 * downstream methods receive a single object instead of many individual parameters.
 *
 * @internal Used only within ItemCreateHandler orchestration
 */
final class IssueCreationState
{
    /** @var array<string, mixed> */
    public array $fields;

    /**
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @param list<string> $requiredFieldIds
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public readonly string $projectKey,
        public readonly string $issueTypeId,
        public readonly array $allFieldsMeta,
        public readonly array $requiredFieldIds,
        array $fields,
    ) {
        $this->fields = $fields;
    }
}
