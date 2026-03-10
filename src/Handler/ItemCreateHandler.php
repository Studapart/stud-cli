<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\Project;
use App\Exception\ApiException;
use App\Response\ItemCreateResponse;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemCreateHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(
        SymfonyStyle $io,
        bool $interactive,
        ?string $project,
        ?string $type,
        ?string $summary,
        ?string $descriptionOption,
        ?string $descriptionFormat = null,
        ?string $parentKey = null,
        ?string $assigneeOption = null,
        ?string $labelsOption = null,
        ?string $originalEstimateOption = null
    ): ItemCreateResponse {
        $projectKeyOrError = $this->resolveProjectKeyOrError($io, $interactive, $project);
        if ($projectKeyOrError instanceof ItemCreateResponse) {
            return $projectKeyOrError;
        }
        $projectKey = $projectKeyOrError;

        $typeExplicitlyProvided = ($parentKey !== null && trim($parentKey) !== '')
            || ($type !== null && trim((string) $type) !== '');
        $type = $this->resolveIssueTypeName($type, $parentKey);

        $summary = $this->resolveSummary($io, $interactive, $summary);
        if ($summary === null || $summary === '') {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_no_summary'));
        }

        $description = $this->getDescription($descriptionOption);

        $issueTypeId = $this->resolveIssueTypeId($projectKey, $type);
        if ($issueTypeId === null) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_createmeta', ['error' => "Issue type \"{$type}\" not found for project \"{$projectKey}\""]));
        }

        try {
            $allFieldsMeta = $this->jiraService->getCreateMetaFields($projectKey, $issueTypeId);
        } catch (\Throwable) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_createmeta', ['error' => 'Could not fetch field metadata']));
        }

        $requiredFieldIds = $this->getRequiredFieldIdsFromMeta($allFieldsMeta);
        $fields = $this->buildBaseFields($projectKey, $issueTypeId, $summary, $description, $descriptionFormat, $parentKey);

        $extraError = $this->resolveExtrasAndMergeIntoFields(
            $io,
            $interactive,
            $projectKey,
            $issueTypeId,
            $typeExplicitlyProvided,
            $summary,
            $fields,
            $allFieldsMeta,
            $requiredFieldIds,
            $assigneeOption,
            $parentKey
        );
        if ($extraError !== null) {
            return $extraError;
        }

        $skippedOptionalFields = $this->applyOptionalFieldsFromCreatemeta(
            $allFieldsMeta,
            $fields,
            $labelsOption,
            $originalEstimateOption
        );

        return $this->createIssueAndReturnResponse($fields, $skippedOptionalFields);
    }

    protected function resolveIssueTypeName(?string $type, ?string $parentKey): string
    {
        if ($parentKey !== null && trim($parentKey) !== '') {
            return 'Sub-task';
        }

        return ($type !== null && trim((string) $type) !== '') ? trim((string) $type) : 'Story';
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildBaseFields(
        string $projectKey,
        string $issueTypeId,
        string $summary,
        ?string $description,
        ?string $descriptionFormat,
        ?string $parentKey
    ): array {
        $fields = [
            'project' => ['key' => $projectKey],
            'issuetype' => ['id' => $issueTypeId],
            'summary' => $summary,
        ];
        if ($description !== null && $description !== '') {
            $format = ($descriptionFormat !== null && trim($descriptionFormat) !== '') ? trim($descriptionFormat) : 'plain';
            $fields['description'] = $this->jiraService->descriptionToAdf($description, $format);
        }
        if ($parentKey !== null && trim($parentKey) !== '') {
            $fields['parent'] = ['key' => trim($parentKey)];
        }

        return $fields;
    }

    /**
     * Resolve extra required fields, merge prompted values into $fields. Returns error response or null.
     *
     * @param array<string, mixed> $fields
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @param list<string> $requiredFieldIds
     */
    protected function resolveExtrasAndMergeIntoFields(
        SymfonyStyle $io,
        bool $interactive,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        string $summary,
        array &$fields,
        array $allFieldsMeta,
        array $requiredFieldIds,
        ?string $assigneeOption,
        ?string $parentKey
    ): ?ItemCreateResponse {
        $parentKeyTrimmed = ($parentKey !== null && trim($parentKey) !== '') ? trim($parentKey) : null;
        $assigneeTrimmed = ($assigneeOption !== null && trim($assigneeOption) !== '') ? trim($assigneeOption) : null;
        $descriptionAdf = $fields['description'] ?? null;

        $extraRequired = $this->resolveStandardFieldsAndExtraRequired(
            $requiredFieldIds,
            $allFieldsMeta,
            $fields,
            $projectKey,
            $issueTypeId,
            $summary,
            $descriptionAdf,
            $typeExplicitlyProvided,
            $interactive,
            $parentKeyTrimmed,
            $assigneeTrimmed
        );
        $this->defaultAssigneeWhenFieldPresent($allFieldsMeta, $fields, $assigneeTrimmed);

        if ($extraRequired === []) {
            return null;
        }

        $prompted = $this->promptForExtraRequiredFields(
            $io,
            $interactive,
            $projectKey,
            $issueTypeId,
            $typeExplicitlyProvided,
            $summary,
            $descriptionAdf,
            $extraRequired
        );
        if ($prompted === null) {
            $fieldsList = $this->getExtraRequiredFieldsList($projectKey, $issueTypeId, $extraRequired);

            return ItemCreateResponse::error($this->translator->trans('item.create.error_extra_required', ['fields' => $fieldsList]));
        }
        foreach ($prompted as $fieldId => $value) {
            $fields[$this->getCreatePayloadFieldKey((string) $fieldId)] = $value;
        }

        return null;
    }

    /**
     * @param array{project: array{key: string}, issuetype: array{id?: string, name?: string}, summary: string, description?: array<string, mixed>} $fields
     * @param list<string> $skippedOptionalFields
     */
    protected function createIssueAndReturnResponse(array $fields, array $skippedOptionalFields): ItemCreateResponse
    {
        try {
            $result = $this->jiraService->createIssue($fields);

            return ItemCreateResponse::success($result['key'], $result['self'], $skippedOptionalFields);
        } catch (ApiException $e) {
            $detail = $e->getTechnicalDetails();
            $error = $detail !== '' ? $e->getMessage() . ' ' . $detail : $e->getMessage();

            return ItemCreateResponse::error($this->translator->trans('item.create.error_create', ['error' => $error]));
        } catch (\Throwable $e) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_create', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Resolves project key or returns an error response. Does not validate against Jira.
     *
     * @return ItemCreateResponse|string Project key string, or error response to return
     */
    protected function resolveProjectKeyOrError(SymfonyStyle $io, bool $interactive, ?string $project): ItemCreateResponse|string
    {
        $projectKey = $this->resolveProjectKey($io, $interactive, $project);
        if ($projectKey === null) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_no_project'));
        }
        $projectDto = $this->ensureProjectExists($io, $interactive, $projectKey);
        if ($projectDto === null) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_project_not_found', ['key' => $projectKey]));
        }

        return $projectDto->key;
    }

    /**
     * Resolves project key from option, config, or interactive prompt. Does not validate against Jira.
     */
    protected function resolveProjectKey(SymfonyStyle $io, bool $interactive, ?string $project): ?string
    {
        if ($project !== null && trim($project) !== '') {
            return trim($project);
        }
        $config = $this->gitRepository->readProjectConfig();
        /** @var array<string, mixed> $config */
        $defaultProject = isset($config['JIRA_DEFAULT_PROJECT']) ? (string) $config['JIRA_DEFAULT_PROJECT'] : null;
        if ($defaultProject !== null && trim($defaultProject) !== '') {
            return trim($defaultProject);
        }
        if (! $interactive) {
            return null;
        }

        return $io->ask($this->translator->trans('item.create.prompt_project'));
    }

    /**
     * Fetches project by key. If not found and interactive, prompts for a new key and retries once.
     * Returns the Project DTO (key, name) so all subsequent logic uses data from Jira.
     */
    protected function ensureProjectExists(SymfonyStyle $io, bool $interactive, string $projectKey): ?Project
    {
        try {
            return $this->jiraService->getProject($projectKey);
        } catch (ApiException $e) {
            if (! $interactive) {
                return null;
            }
            $newKey = $io->ask($this->translator->trans('item.create.prompt_project_not_found', ['key' => $projectKey]));
            if ($newKey === null || trim($newKey) === '') {
                return null;
            }

            try {
                return $this->jiraService->getProject(trim($newKey));
            } catch (ApiException) {
                return null;
            }
        }
    }

    protected function resolveSummary(SymfonyStyle $io, bool $interactive, ?string $summary): ?string
    {
        $normalized = $summary !== null ? trim((string) $summary) : '';
        if ($normalized !== '') {
            return $normalized;
        }
        if (! $interactive) {
            return null;
        }

        return $io->ask($this->translator->trans('item.create.prompt_summary'));
    }

    /**
     * Description precedence: STDIN first, then option. Same as pr:comment.
     */
    protected function getDescription(?string $descriptionOption): ?string
    {
        $stdinContent = $this->readStdin();
        if ($stdinContent !== '') {
            // STDIN content path only reachable when readStdin() returns non-empty; not unit-testable without process
            // @codeCoverageIgnoreStart
            return $stdinContent;
            // @codeCoverageIgnoreEnd
        }
        if ($descriptionOption !== null && trim($descriptionOption) !== '') {
            return trim($descriptionOption);
        }

        return null;
    }

    /**
     * Reads content from STDIN if available (non-blocking). Returns empty string if TTY or no content.
     * Same behaviour as PrCommentHandler::readStdin(); STDIN paths are not unit-testable without process execution.
     *
     * @codeCoverageIgnore
     */
    protected function readStdin(): string
    {
        // TTY check and STDIN reading not simulable in unit tests without process execution
        // @codeCoverageIgnoreStart
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            return '';
        }
        if (is_resource(STDIN)) {
            $metaData = stream_get_meta_data(STDIN);
            $wasBlocking = $metaData['blocked'];
            stream_set_blocking(STDIN, false);
            $content = stream_get_contents(STDIN);
            stream_set_blocking(STDIN, $wasBlocking);

            if ($content !== false) {
                return trim($content);
            }
        }
        if (! function_exists('posix_isatty') || ! posix_isatty(STDIN)) {
            $content = @file_get_contents('php://stdin');

            return $content !== false ? trim($content) : '';
        }
        // @codeCoverageIgnoreEnd

        return '';
    }

    /**
     * @return string|null Issue type id or null if not found / API error
     */
    protected function resolveIssueTypeId(string $projectKey, string $typeName): ?string
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
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @return list<string>
     */
    protected function getRequiredFieldIdsFromMeta(array $allFieldsMeta): array
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
     * When there are required fields not filled from CLI, prompt for them (interactive only).
     * Returns null if not interactive so caller can show error_extra_required.
     * Project, issuetype, summary and description are taken from already-resolved values when present.
     *
     * @param list<string> $extraRequiredFieldIds Required field IDs that still need values (not yet filled)
     * @param array<string, mixed>|null $descriptionAdf Description as ADF if already provided
     * @return array<string, mixed>|null Map of fieldId => value, or null when not interactive
     */
    protected function promptForExtraRequiredFields(
        SymfonyStyle $io,
        bool $interactive,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        string $summary,
        ?array $descriptionAdf,
        array $extraRequiredFieldIds
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
            $nameLower = strtolower($name);
            $fieldIdLower = strtolower($fieldId);

            $value = $this->getPromptedValueForExtraField(
                $io,
                $fieldId,
                $fieldIdLower,
                $name,
                $nameLower,
                $projectKey,
                $issueTypeId,
                $typeExplicitlyProvided,
                $summary,
                $descriptionAdf,
                $allFields
            );
            if ($value !== null) {
                foreach ($value as $fid => $v) {
                    $result[$fid] = $v;
                }
            }
        }

        return $result;
    }

    /**
     * Resolve value for one extra-required field (standard or custom). Returns map fieldId => value, or null to skip.
     *
     * @param array<string, mixed> $allFields
     * @param array<string, mixed>|null $descriptionAdf
     * @return array<string, mixed>|null
     */
    protected function getPromptedValueForExtraField(
        SymfonyStyle $io,
        string $fieldId,
        string $fieldIdLower,
        string $name,
        string $nameLower,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        string $summary,
        ?array $descriptionAdf,
        array $allFields
    ): ?array {
        $standardKind = $this->extraFieldStandardKind($fieldIdLower, $nameLower);
        if ($standardKind !== null) {
            return $this->getValueForStandardExtraField(
                $standardKind,
                $io,
                $fieldId,
                $projectKey,
                $issueTypeId,
                $typeExplicitlyProvided,
                $summary,
                $descriptionAdf,
                $allFields
            );
        }
        $value = $io->ask($this->translator->trans('item.create.prompt_custom_field', ['name' => $name]));
        if ($value === null || trim($value) === '') {
            return null;
        }

        return [$fieldId => trim($value)];
    }

    /** @var array<string, array<string>> */
    private const EXTRA_FIELD_KIND_KEYS = [
        'project' => ['project'],
        'reporter' => ['reporter'],
        'assignee' => ['assignee'],
        'issuetype' => ['issuetype', 'issue type'],
        'summary' => ['summary'],
        'description' => ['description'],
    ];

    protected function extraFieldStandardKind(string $fieldIdLower, string $nameLower): ?string
    {
        foreach (self::EXTRA_FIELD_KIND_KEYS as $kind => $keys) {
            if (in_array($fieldIdLower, $keys, true) || in_array($nameLower, $keys, true)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $allFields
     * @param array<string, mixed>|null $descriptionAdf
     * @return array<string, mixed>|null
     */
    protected function getValueForStandardExtraField(
        string $kind,
        SymfonyStyle $io,
        string $fieldId,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        string $summary,
        ?array $descriptionAdf,
        array $allFields
    ): ?array {
        return match ($kind) {
            'project' => [$fieldId => ['key' => $projectKey]],
            'reporter' => [$fieldId => ['accountId' => $this->jiraService->getCurrentUserAccountId()]],
            'assignee' => [$fieldId => ['accountId' => $this->jiraService->getCurrentUserAccountId()]],
            'issuetype' => $this->promptIssueTypeValue($io, $projectKey, $issueTypeId, $typeExplicitlyProvided, $allFields),
            'summary' => [$fieldId => $summary],
            'description' => $this->valueForDescriptionExtraField($io, $descriptionAdf, $fieldId),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $allFields
     * @return array<string, mixed>|null Map of fieldId => ['id' => $chosenId] for all issuetype fields, or null
     */
    protected function promptIssueTypeValue(
        SymfonyStyle $io,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        array $allFields
    ): ?array {
        $chosenId = $typeExplicitlyProvided ? $issueTypeId : $this->chooseIssueTypeInteractively($io, $projectKey);
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

    protected function chooseIssueTypeInteractively(SymfonyStyle $io, string $projectKey): ?string
    {
        $issueTypes = $this->jiraService->getCreateMetaIssueTypes($projectKey);
        if ($issueTypes === []) {
            return null;
        }
        $choices = array_column($issueTypes, 'name');
        $selectedName = $io->choice($this->translator->trans('item.create.prompt_issue_type_choice'), $choices);
        foreach ($issueTypes as $it) {
            if ($it['name'] === $selectedName) {
                return $it['id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $descriptionAdf
     * @return array<string, mixed>|null
     */
    protected function valueForDescriptionExtraField(SymfonyStyle $io, ?array $descriptionAdf, string $fieldId): ?array
    {
        $v = $this->promptDescriptionValue($io, $descriptionAdf);

        return $v !== null ? [$fieldId => $v] : null;
    }

    /**
     * @param array<string, mixed>|null $descriptionAdf
     * @return array<string, mixed>|string|null
     */
    protected function promptDescriptionValue(SymfonyStyle $io, ?array $descriptionAdf): array|string|null
    {
        if ($descriptionAdf !== null) {
            return $descriptionAdf;
        }
        $answer = $io->ask($this->translator->trans('item.create.prompt_description_required'));
        if ($answer === null || trim($answer) === '') {
            return null;
        }

        return $this->jiraService->plainTextToDescriptionAdf(trim($answer));
    }

    /**
     * Fills standard fields (Project, Reporter, Assignee, Summary, Description, Issue Type, Parent) from metadata by name,
     * and returns the list of required field IDs that are not standard (i.e. need to be prompted or error).
     *
     * @param list<string> $requiredFieldIds
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $descriptionAdf Description as ADF
     * @return list<string>
     */
    protected function resolveStandardFieldsAndExtraRequired(
        array $requiredFieldIds,
        array $allFieldsMeta,
        array &$fields,
        string $projectKey,
        string $issueTypeId,
        string $summary,
        ?array $descriptionAdf,
        bool $typeExplicitlyProvided,
        bool $interactive,
        ?string $parentKey = null,
        ?string $assigneeOption = null
    ): array {
        $extraRequired = [];
        $fillIssueType = $typeExplicitlyProvided || ! $interactive;
        $issueTypeAddedToExtra = false;

        foreach ($requiredFieldIds as $fieldId) {
            $fieldId = (string) $fieldId;
            $name = (string) ($allFieldsMeta[$fieldId]['name'] ?? $fieldId);
            $nameLower = strtolower($name);

            if ($this->fillStandardFieldByName(
                $nameLower,
                $fields,
                $projectKey,
                $issueTypeId,
                $summary,
                $descriptionAdf,
                $assigneeOption,
                $parentKey,
                $fillIssueType
            )) {
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
     * @param array<string, mixed>|null $descriptionAdf
     */
    protected function fillStandardFieldByName(
        string $nameLower,
        array &$fields,
        string $projectKey,
        string $issueTypeId,
        string $summary,
        ?array $descriptionAdf,
        ?string $assigneeOption,
        ?string $parentKey,
        bool $fillIssueType
    ): bool {
        $filled = match ($nameLower) {
            'project' => true,
            'reporter' => true,
            'assignee' => true,
            'summary' => true,
            'description' => $descriptionAdf !== null,
            'issue type', 'issuetype' => $fillIssueType,
            'parent' => $parentKey !== null,
            default => false,
        };
        if (! $filled) {
            return false;
        }
        $this->applyStandardFieldValue($nameLower, $fields, $projectKey, $issueTypeId, $summary, $descriptionAdf, $assigneeOption, $parentKey);

        return true;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $descriptionAdf
     */
    protected function applyStandardFieldValue(
        string $nameLower,
        array &$fields,
        string $projectKey,
        string $issueTypeId,
        string $summary,
        ?array $descriptionAdf,
        ?string $assigneeOption,
        ?string $parentKey
    ): void {
        match ($nameLower) {
            'project' => $fields['project'] = ['key' => $projectKey],
            'reporter' => $fields['reporter'] = ['accountId' => $this->jiraService->getCurrentUserAccountId()],
            'assignee' => $fields['assignee'] = ['accountId' => $assigneeOption ?? $this->jiraService->getCurrentUserAccountId()],
            'summary' => $fields['summary'] = $summary,
            'description' => $fields['description'] = $descriptionAdf ?? [],
            'issue type', 'issuetype' => $fields['issuetype'] = ['id' => $issueTypeId],
            'parent' => $fields['parent'] = ['key' => $parentKey ?? ''],
            default => null,
        };
    }

    /**
     * When the create screen has an Assignee field and we have no value yet, set assignee to --assignee or current user.
     *
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @param array<string, mixed> $fields
     */
    protected function defaultAssigneeWhenFieldPresent(array $allFieldsMeta, array &$fields, ?string $assigneeOption): void
    {
        if (isset($fields['assignee'])) {
            return;
        }
        foreach ($allFieldsMeta as $meta) {
            $name = (string) $meta['name'];
            if (strtolower($name) === 'assignee') {
                $accountId = $assigneeOption !== null
                    ? $assigneeOption
                    : $this->jiraService->getCurrentUserAccountId();
                $fields['assignee'] = ['accountId' => $accountId];

                break;
            }
        }
    }

    /**
     * Returns the field key to use in the create-issue payload.
     * Jira expects custom fields as "customfield_XXXXX"; createmeta may return numeric IDs.
     */
    protected function getCreatePayloadFieldKey(string $fieldId): string
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
     * Applies optional fields (labels, time original estimate) when present in createmeta and user supplied values.
     * Returns list of human-readable field names that were requested but skipped (not in createmeta or invalid format).
     *
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     * @param array<string, mixed> $fields Create payload; modified in place
     * @return list<string>
     */
    protected function applyOptionalFieldsFromCreatemeta(
        array $allFieldsMeta,
        array &$fields,
        ?string $labelsOption,
        ?string $originalEstimateOption
    ): array {
        $skipped = [];
        $labelsPayloadKey = $this->findOptionalFieldKey($allFieldsMeta, 'labels', 'labels');
        $estimatePayloadKey = $this->findOptionalFieldKey($allFieldsMeta, 'timeoriginalestimate', 'time original estimate');

        if ($labelsOption !== null && trim($labelsOption) !== '') {
            if ($labelsPayloadKey !== null) {
                $labels = array_values(array_filter(array_map('trim', explode(',', $labelsOption))));
                if ($labels !== []) {
                    $fields['labels'] = $labels;
                }
            } else {
                $skipped[] = $this->translator->trans('item.create.skipped_field_labels');
            }
        }

        if ($originalEstimateOption !== null && trim($originalEstimateOption) !== '') {
            $seconds = $this->parseOriginalEstimateToSeconds(trim($originalEstimateOption));
            if ($seconds === null) {
                $skipped[] = $this->translator->trans('item.create.skipped_field_original_estimate');
            } elseif ($estimatePayloadKey !== null) {
                $fields['timeoriginalestimate'] = $seconds;
            } else {
                $skipped[] = $this->translator->trans('item.create.skipped_field_original_estimate');
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

    /**
     * Parses human-friendly duration (e.g. 1d, 0.5d, 1 day, 2h, 30m) to seconds.
     * Supports: d/day/days, h/hour/hours, m/min/minute/minutes. Accepts decimals (e.g. 0.5d).
     *
     * @return int|null Seconds, or null if input is invalid
     */
    protected function parseOriginalEstimateToSeconds(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*(d|day|days|h|hour|hours|m|min|minute|minutes)\s*$/i', $value, $m)) {
            return null;
        }
        $num = (float) $m[1];
        $unit = strtolower($m[2]);
        $seconds = $this->durationUnitToSeconds($num, $unit);

        return $seconds !== null && $seconds >= 0 ? $seconds : null;
    }

    /**
     * Convert a duration number and unit to seconds.
     */
    protected function durationUnitToSeconds(float $num, string $unit): ?int
    {
        $multiplier = $this->durationUnitToSecondsMultiplier($unit);

        return $multiplier !== null ? (int) round($num * $multiplier) : null;
    }

    /** @var array<string, float> */
    private const DURATION_UNIT_MULTIPLIERS = [
        'd' => 86400.0, 'day' => 86400.0, 'days' => 86400.0,
        'h' => 3600.0, 'hour' => 3600.0, 'hours' => 3600.0,
        'm' => 60.0, 'min' => 60.0, 'minute' => 60.0, 'minutes' => 60.0,
    ];

    protected function durationUnitToSecondsMultiplier(string $unit): ?float
    {
        return self::DURATION_UNIT_MULTIPLIERS[$unit] ?? null;
    }

    /**
     * Builds a human-readable list of extra required field names (and IDs) for error messages.
     *
     * @param list<string> $extraRequiredFieldIds
     */
    protected function getExtraRequiredFieldsList(string $projectKey, string $issueTypeId, array $extraRequiredFieldIds): string
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
