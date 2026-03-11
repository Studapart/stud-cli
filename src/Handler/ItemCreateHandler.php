<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\IssueCreationState;
use App\DTO\ItemCreateInput;
use App\DTO\Project;
use App\Exception\ApiException;
use App\Response\ItemCreateResponse;
use App\Service\GitRepository;
use App\Service\IssueFieldResolver;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemCreateHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator,
        private readonly IssueFieldResolver $fieldResolver
    ) {
    }

    public function handle(SymfonyStyle $io, bool $interactive, ItemCreateInput $input): ItemCreateResponse
    {
        $projectKeyOrError = $this->resolveProjectKeyOrError($io, $interactive, $input->project);
        if ($projectKeyOrError instanceof ItemCreateResponse) {
            return $projectKeyOrError;
        }
        $projectKey = $projectKeyOrError;
        $type = $this->fieldResolver->resolveIssueTypeName($input->type, $input->parentKey);

        $summary = $this->resolveSummary($io, $interactive, $input->summary);
        if ($summary === null || $summary === '') {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_no_summary'));
        }

        $stateOrError = $this->resolveTypeMetadata($projectKey, $type, $summary, $input);
        if ($stateOrError instanceof ItemCreateResponse) {
            return $stateOrError;
        }

        $extraError = $this->resolveExtrasAndMergeIntoFields($io, $interactive, $stateOrError, $input);
        if ($extraError !== null) {
            return $extraError;
        }

        $skippedOptionalFields = $this->fieldResolver->applyOptionalFieldsFromCreatemeta(
            $stateOrError->allFieldsMeta,
            $stateOrError->fields,
            $input->labelsOption,
            $input->originalEstimateOption,
            $this->translator
        );

        return $this->createIssueAndReturnResponse($stateOrError->fields, $skippedOptionalFields);
    }

    protected function resolveTypeMetadata(
        string $projectKey,
        string $type,
        string $summary,
        ItemCreateInput $input
    ): IssueCreationState|ItemCreateResponse {
        $issueTypeId = $this->fieldResolver->resolveIssueTypeId($projectKey, $type);
        if ($issueTypeId === null) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_createmeta', ['error' => "Issue type \"{$type}\" not found for project \"{$projectKey}\""]));
        }

        try {
            $allFieldsMeta = $this->jiraService->getCreateMetaFields($projectKey, $issueTypeId);
        } catch (\Throwable) {
            return ItemCreateResponse::error($this->translator->trans('item.create.error_createmeta', ['error' => 'Could not fetch field metadata']));
        }

        $description = $this->getDescription($input->descriptionOption);
        $requiredFieldIds = $this->fieldResolver->getRequiredFieldIdsFromMeta($allFieldsMeta);
        $fields = $this->fieldResolver->buildBaseFields($projectKey, $issueTypeId, $summary, $description, $input->descriptionFormat, $input->parentKey);

        return new IssueCreationState($projectKey, $issueTypeId, $allFieldsMeta, $requiredFieldIds, $fields);
    }

    /**
     * Resolve extra required fields, merge prompted values into state. Returns error response or null.
     */
    protected function resolveExtrasAndMergeIntoFields(
        SymfonyStyle $io,
        bool $interactive,
        IssueCreationState $state,
        ItemCreateInput $input
    ): ?ItemCreateResponse {
        $typeExplicitlyProvided = ($input->parentKey !== null && trim($input->parentKey) !== '')
            || ($input->type !== null && trim((string) $input->type) !== '');
        $parentKeyTrimmed = ($input->parentKey !== null && trim($input->parentKey) !== '') ? trim($input->parentKey) : null;
        $assigneeTrimmed = ($input->assigneeOption !== null && trim($input->assigneeOption) !== '') ? trim($input->assigneeOption) : null;
        $descriptionAdf = $state->fields['description'] ?? null;

        $fieldValues = [
            'projectKey' => $state->projectKey,
            'issueTypeId' => $state->issueTypeId,
            'summary' => (string) $state->fields['summary'],
            'descriptionAdf' => $descriptionAdf,
            'assigneeOption' => $assigneeTrimmed,
            'parentKey' => $parentKeyTrimmed,
        ];
        $fillIssueType = $typeExplicitlyProvided || ! $interactive;
        $extraRequired = $this->fieldResolver->resolveStandardFieldsAndExtraRequired(
            $state->requiredFieldIds,
            $state->allFieldsMeta,
            $state->fields,
            $fieldValues,
            $fillIssueType
        );
        $this->fieldResolver->defaultAssigneeWhenFieldPresent($state->allFieldsMeta, $state->fields, $assigneeTrimmed);

        if ($extraRequired === []) {
            return null;
        }

        return $this->promptAndMergeExtraFields(
            $io,
            $interactive,
            $state,
            $typeExplicitlyProvided,
            $descriptionAdf,
            $extraRequired
        );
    }

    /**
     * Prompt for extra required fields and merge values into state. Returns error response or null.
     *
     * @param list<string> $extraRequired
     * @param array<string, mixed>|null $descriptionAdf
     */
    protected function promptAndMergeExtraFields(
        SymfonyStyle $io,
        bool $interactive,
        IssueCreationState $state,
        bool $typeExplicitlyProvided,
        ?array $descriptionAdf,
        array $extraRequired
    ): ?ItemCreateResponse {
        $prompted = $this->promptForExtraRequiredFields(
            $io,
            $interactive,
            $state->projectKey,
            $state->issueTypeId,
            $typeExplicitlyProvided,
            (string) $state->fields['summary'],
            $descriptionAdf,
            $extraRequired
        );
        if ($prompted === null) {
            $fieldsList = $this->fieldResolver->getExtraRequiredFieldsList(
                $state->projectKey,
                $state->issueTypeId,
                $extraRequired
            );

            return ItemCreateResponse::error($this->translator->trans('item.create.error_extra_required', ['fields' => $fieldsList]));
        }
        foreach ($prompted as $fieldId => $value) {
            $state->fields[$this->fieldResolver->getCreatePayloadFieldKey((string) $fieldId)] = $value;
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

        return ($descriptionOption !== null && trim($descriptionOption) !== '') ? trim($descriptionOption) : null;
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
     * When there are required fields not filled from CLI, prompt for them (interactive only).
     * Returns null if not interactive so caller can show error_extra_required.
     *
     * @param list<string> $extraRequiredFieldIds
     * @param array<string, mixed>|null $descriptionAdf
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
            $value = $this->getPromptedValueForExtraField(
                $io,
                $fieldId,
                $name,
                $projectKey,
                $issueTypeId,
                $typeExplicitlyProvided,
                $summary,
                $descriptionAdf,
                $allFields
            );
            if ($value !== null) {
                $result += $value;
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
        string $name,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        string $summary,
        ?array $descriptionAdf,
        array $allFields
    ): ?array {
        $standardKind = $this->fieldResolver->extraFieldStandardKind(strtolower($fieldId), strtolower($name));
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
            'description' => ($v = $this->promptDescriptionValue($io, $descriptionAdf)) !== null ? [$fieldId => $v] : null,
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
}
