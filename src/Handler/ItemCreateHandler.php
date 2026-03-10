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
        $projectKey = $this->resolveProjectKey($io, $interactive, $project);
        if ($projectKey === null) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_no_project'));
        }

        $projectDto = $this->ensureProjectExists($io, $interactive, $projectKey);
        if ($projectDto === null) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_project_not_found', ['key' => $projectKey]));
        }
        $projectKey = $projectDto->key;

        $typeExplicitlyProvided = $type !== null && trim((string) $type) !== '';
        if ($parentKey !== null && trim($parentKey) !== '') {
            $type = 'Sub-task';
            $typeExplicitlyProvided = true;
        } else {
            $type = $typeExplicitlyProvided ? trim((string) $type) : 'Story';
        }

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

        $descriptionAdf = $fields['description'] ?? null;
        $parentKeyTrimmed = ($parentKey !== null && trim($parentKey) !== '') ? trim($parentKey) : null;
        $assigneeTrimmed = ($assigneeOption !== null && trim($assigneeOption) !== '') ? trim($assigneeOption) : null;
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

        if ($extraRequired !== []) {
            $prompted = $this->promptForExtraRequiredFields(
                $io,
                $interactive,
                $projectKey,
                $issueTypeId,
                $typeExplicitlyProvided,
                $summary,
                $fields['description'] ?? null,
                $extraRequired
            );
            if ($prompted === null) {
                $fieldsList = $this->getExtraRequiredFieldsList($projectKey, $issueTypeId, $extraRequired);

                return ItemCreateResponse::error($this->translator->trans('item.create.error_extra_required', ['fields' => $fieldsList]));
            }
            foreach ($prompted as $fieldId => $value) {
                $payloadKey = $this->getCreatePayloadFieldKey((string) $fieldId);
                $fields[$payloadKey] = $value;
            }
        }

        $skippedOptionalFields = $this->applyOptionalFieldsFromCreatemeta(
            $allFieldsMeta,
            $fields,
            $labelsOption,
            $originalEstimateOption
        );

        try {
            $result = $this->jiraService->createIssue($fields);

            return ItemCreateResponse::success($result['key'], $result['self'], $skippedOptionalFields);
        } catch (ApiException $e) {
            $detail = $e->getTechnicalDetails();
            $error = $detail !== '' ? $e->getMessage() . ' ' . $detail : $e->getMessage();

            return ItemCreateResponse::error($this->translator->trans('item.create.error_create', ['error' => $error]));
        } catch (\Throwable $e) {
            // Non-API throwables (e.g. network, JSON) mapped to same user-facing error
            return ItemCreateResponse::error($this->translator->trans('item.create.error_create', ['error' => $e->getMessage()]));
        }
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

            if ($fieldIdLower === 'project' || $nameLower === 'project') {
                $result[$fieldId] = ['key' => $projectKey];

                continue;
            }
            if ($fieldIdLower === 'reporter' || $nameLower === 'reporter') {
                $result[$fieldId] = ['accountId' => $this->jiraService->getCurrentUserAccountId()];

                continue;
            }
            if ($fieldIdLower === 'assignee' || $nameLower === 'assignee') {
                $result[$fieldId] = ['accountId' => $this->jiraService->getCurrentUserAccountId()];

                continue;
            }
            if ($fieldIdLower === 'issuetype' || $nameLower === 'issue type') {
                $chosenId = null;
                if ($typeExplicitlyProvided) {
                    $chosenId = $issueTypeId;
                } else {
                    $issueTypes = $this->jiraService->getCreateMetaIssueTypes($projectKey);
                    if ($issueTypes !== []) {
                        $choices = array_column($issueTypes, 'name');
                        $question = $this->translator->trans('item.create.prompt_issue_type_choice');
                        $selectedName = $io->choice($question, $choices);
                        foreach ($issueTypes as $it) {
                            if ($it['name'] === $selectedName) {
                                $chosenId = $it['id'];

                                break;
                            }
                        }
                    }
                }
                if ($chosenId !== null) {
                    $value = ['id' => $chosenId];
                    foreach ($allFields as $fid => $meta) {
                        $n = strtolower((string) $meta['name']);
                        if ($n === 'issue type' || $n === 'issuetype') {
                            $result[(string) $fid] = $value;
                        }
                    }
                }

                continue;
            }
            if ($fieldIdLower === 'summary' || $nameLower === 'summary') {
                $result[$fieldId] = $summary;

                continue;
            }
            if ($fieldIdLower === 'description' || $nameLower === 'description') {
                if ($descriptionAdf !== null) {
                    $result[$fieldId] = $descriptionAdf;
                } else {
                    $answer = $io->ask($this->translator->trans('item.create.prompt_description_required'));
                    if ($answer !== null && trim($answer) !== '') {
                        $result[$fieldId] = $this->jiraService->plainTextToDescriptionAdf(trim($answer));
                    }
                }

                continue;
            }

            $value = $io->ask($this->translator->trans('item.create.prompt_custom_field', ['name' => $name]));
            if ($value === null || $value === '') {
                continue;
            }
            $result[$fieldId] = trim($value);
        }

        return $result;
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

            // Use Jira REST API standard field names only. Do not send customfield_XX for system fields
            // (e.g. createmeta may return 15, 19, 20, 21 for Issue Type, Project, Reporter, Summary);
            // the create-issue API expects "issuetype", "project", "reporter", "summary".
            if ($nameLower === 'project') {
                $fields['project'] = ['key' => $projectKey];

                continue;
            }
            if ($nameLower === 'reporter') {
                $fields['reporter'] = ['accountId' => $this->jiraService->getCurrentUserAccountId()];

                continue;
            }
            if ($nameLower === 'assignee') {
                $accountId = $assigneeOption !== null
                    ? $assigneeOption
                    : $this->jiraService->getCurrentUserAccountId();
                $fields['assignee'] = ['accountId' => $accountId];

                continue;
            }
            if ($nameLower === 'summary') {
                $fields['summary'] = $summary;

                continue;
            }
            if ($nameLower === 'description' && $descriptionAdf !== null) {
                $fields['description'] = $descriptionAdf;

                continue;
            }
            if ($nameLower === 'issue type' || $nameLower === 'issuetype') {
                if ($fillIssueType) {
                    $fields['issuetype'] = ['id' => $issueTypeId];
                } elseif (! $issueTypeAddedToExtra) {
                    $extraRequired[] = $fieldId;
                    $issueTypeAddedToExtra = true;
                }

                continue;
            }
            if ($nameLower === 'parent' && $parentKey !== null) {
                $fields['parent'] = ['key' => $parentKey];

                continue;
            }

            // Truly custom field (not a standard system field): must be prompted or error
            $extraRequired[] = $fieldId;
        }

        return $extraRequired;
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
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(d|day|days|h|hour|hours|m|min|minute|minutes)\s*$/i', $value, $m)) {
            $num = (float) $m[1];
            $unit = strtolower($m[2]);
            $seconds = match (true) {
                $unit === 'd' || $unit === 'day' || $unit === 'days' => (int) round($num * 86400),
                $unit === 'h' || $unit === 'hour' || $unit === 'hours' => (int) round($num * 3600),
                $unit === 'm' || $unit === 'min' || $unit === 'minute' || $unit === 'minutes' => (int) round($num * 60),
                // Regex above only allows d/day/days, h/hour/hours, m/min/minute/minutes; default unreachable
                // @codeCoverageIgnoreStart
                default => null,
                // @codeCoverageIgnoreEnd
            };

            return $seconds !== null && $seconds >= 0 ? $seconds : null;
        }

        return null;
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
