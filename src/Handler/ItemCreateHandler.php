<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\IssueCreationState;
use App\DTO\ItemCreateInput;
use App\DTO\MessageRef;
use App\Exception\ApiException;
use App\Guard\Capability\WorkItemJiraAware;
use App\Response\ItemCreateResponse;
use App\Service\FieldsParser;
use App\Service\IssueFieldBagKeys;
use App\Service\IssueFieldResolver;
use App\Service\IssueTrackerLabelGroupsCapable;
use App\Service\IssueTrackerPort;
use App\Service\ItemCreateProjectResolver;
use App\Service\ItemCreatePromptService;
use App\Service\Prompt\PromptInterface;

class ItemCreateHandler implements WorkItemJiraAware
{
    public function __construct(
        private readonly ItemCreateProjectResolver $projectResolver,
        private readonly ItemCreatePromptService $promptService,
        private readonly IssueTrackerPort $provider,
        private readonly IssueFieldResolver $fieldResolver,
        private readonly FieldsParser $fieldsParser,
        private readonly PromptInterface $prompt,
    ) {
    }

    public function handle(bool $interactive, ItemCreateInput $input): ItemCreateResponse
    {
        $projectKeyOrError = $this->resolveProjectKeyOrError($interactive, $input->project);
        if ($projectKeyOrError instanceof ItemCreateResponse) {
            return $projectKeyOrError;
        }
        $projectKey = $projectKeyOrError;
        $type = $this->fieldResolver->resolveIssueTypeName($input->type, $input->parentKey);

        $summary = $this->resolveSummary($interactive, $input->summary);
        if ($summary === null || $summary === '') {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_no_summary'));
        }

        $stateOrError = $this->resolveTypeMetadata($projectKey, $type, $summary, $input);
        if ($stateOrError instanceof ItemCreateResponse) {
            return $stateOrError;
        }

        $extraError = $this->resolveExtrasAndMergeIntoFields($interactive, $stateOrError, $input);
        if ($extraError !== null) {
            return $extraError;
        }

        $skippedOptionalFields = $this->applyFieldsOption($stateOrError, $input);

        return $this->createIssueAndReturnResponse($stateOrError->fields, $skippedOptionalFields);
    }

    /**
     * @return list<string>
     */
    protected function applyFieldsOption(IssueCreationState $state, ItemCreateInput $input): array
    {
        $parsedFields = $input->fieldsMap ?? ($input->fieldsOption !== null ? $this->fieldsParser->parse($input->fieldsOption) : []);
        if ($parsedFields === []) {
            return [];
        }
        $result = $this->fieldsParser->matchAndTransform($parsedFields, $state->allFieldsMeta);
        foreach ($result['matched'] as $key => $value) {
            $state->fields[$key] = $value;
        }

        return $result['unmatched'];
    }

    protected function resolveTypeMetadata(
        string $projectKey,
        string $type,
        string $summary,
        ItemCreateInput $input
    ): IssueCreationState|ItemCreateResponse {
        if ($this->provider instanceof IssueTrackerLabelGroupsCapable) {
            return $this->resolveLabelGroupsCapableCreateMetadata($projectKey, $type, $summary, $input);
        }

        $issueTypeId = $this->fieldResolver->resolveIssueTypeId($projectKey, $type);
        if ($issueTypeId === null) {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_createmeta', ['error' => "Issue type \"{$type}\" not found for project \"{$projectKey}\""]));
        }

        try {
            $allFieldsMeta = $this->provider->getCreateMetaFields($projectKey, $issueTypeId);
        } catch (\Throwable) {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_createmeta', ['error' => 'Could not fetch field metadata']));
        }

        $description = $this->getDescription($input->descriptionOption);
        $requiredFieldIds = $this->fieldResolver->getRequiredFieldIdsFromMeta($allFieldsMeta);
        $fields = $this->fieldResolver->buildBaseFields($projectKey, $issueTypeId, $summary, $description, $input->descriptionFormat, $input->parentKey);

        return new IssueCreationState($projectKey, $issueTypeId, $allFieldsMeta, $requiredFieldIds, $fields);
    }

    protected function resolveLabelGroupsCapableCreateMetadata(
        string $projectKey,
        string $type,
        string $summary,
        ItemCreateInput $input,
    ): IssueCreationState|ItemCreateResponse {
        try {
            $allFieldsMeta = $this->provider->getCreateMetaFields($projectKey, $type);
        } catch (\Throwable) {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_createmeta', ['error' => 'Could not fetch field metadata']));
        }

        $description = $this->getDescription($input->descriptionOption);
        $fields = [
            IssueFieldBagKeys::PROJECT => [IssueFieldBagKeys::KEY => $projectKey],
            IssueFieldBagKeys::ISSUE_TYPE => [IssueFieldBagKeys::NAME => $type],
            IssueFieldBagKeys::SUMMARY => $summary,
        ];

        if ($description !== null && $description !== '') {
            $format = ($input->descriptionFormat !== null && trim($input->descriptionFormat) !== '')
                ? trim($input->descriptionFormat)
                : 'plain';
            $fields[IssueFieldBagKeys::DESCRIPTION] = $this->provider->formatDescription($description, $format);
        }

        if ($input->parentKey !== null && trim($input->parentKey) !== '') {
            $fields[IssueFieldBagKeys::PARENT] = [IssueFieldBagKeys::KEY => trim($input->parentKey)];
        }

        return new IssueCreationState($projectKey, $type, $allFieldsMeta, [], $fields);
    }

    protected function resolveExtrasAndMergeIntoFields(
        bool $interactive,
        IssueCreationState $state,
        ItemCreateInput $input
    ): ?ItemCreateResponse {
        $typeExplicitlyProvided = ($input->parentKey !== null && trim($input->parentKey) !== '')
            || ($input->type !== null && trim((string) $input->type) !== '');
        $parentKeyTrimmed = ($input->parentKey !== null && trim($input->parentKey) !== '') ? trim($input->parentKey) : null;
        $descriptionAdf = $state->fields['description'] ?? null;

        $fieldValues = [
            'projectKey' => $state->projectKey,
            'issueTypeId' => $state->issueTypeId,
            'summary' => (string) $state->fields['summary'],
            'descriptionAdf' => $descriptionAdf,
            'assigneeOption' => null,
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
        $this->fieldResolver->defaultAssigneeWhenFieldPresent($state->allFieldsMeta, $state->fields, null);

        if ($extraRequired === []) {
            return null;
        }

        return $this->promptAndMergeExtraFields(
            $interactive,
            $state,
            $typeExplicitlyProvided,
            $descriptionAdf,
            $extraRequired
        );
    }

    /**
     * @param list<string> $extraRequired
     * @param array<string, mixed>|null $descriptionAdf
     */
    protected function promptAndMergeExtraFields(
        bool $interactive,
        IssueCreationState $state,
        bool $typeExplicitlyProvided,
        ?array $descriptionAdf,
        array $extraRequired
    ): ?ItemCreateResponse {
        $prompted = $this->promptForExtraRequiredFields(
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

            return ItemCreateResponse::error(MessageRef::key('item.create.error_extra_required', ['fields' => $fieldsList]));
        }
        foreach ($prompted as $fieldId => $value) {
            $state->fields[$this->fieldResolver->getCreatePayloadFieldKey((string) $fieldId)] = $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $skippedOptionalFields
     */
    protected function createIssueAndReturnResponse(array $fields, array $skippedOptionalFields): ItemCreateResponse
    {
        try {
            $result = $this->provider->create($fields);

            return ItemCreateResponse::success($result['key'], $result['self'], $skippedOptionalFields);
        } catch (ApiException $e) {
            $detail = $e->getTechnicalDetails();
            $error = $detail !== '' ? $e->getMessage() . ' ' . $detail : $e->getMessage();

            return ItemCreateResponse::error(MessageRef::key('item.create.error_create', ['error' => $error]));
        } catch (\Throwable $e) {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_create', ['error' => $e->getMessage()]));
        }
    }

    /**
     * @return ItemCreateResponse|string
     */
    protected function resolveProjectKeyOrError(bool $interactive, ?string $project): ItemCreateResponse|string
    {
        $projectKey = $this->projectResolver->resolveProjectKey($interactive, $project);
        if ($projectKey === null) {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_no_project'));
        }
        $projectDto = $this->projectResolver->ensureProjectExists($interactive, $projectKey);
        if ($projectDto === null) {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_project_not_found', ['key' => $projectKey]));
        }

        return $projectDto->key;
    }

    protected function resolveSummary(bool $interactive, ?string $summary): ?string
    {
        $normalized = $summary !== null ? trim((string) $summary) : '';
        if ($normalized !== '') {
            return $normalized;
        }
        if (! $interactive) {
            return null;
        }

        return $this->prompt->ask(MessageRef::key('item.create.prompt_summary'));
    }

    protected function getDescription(?string $descriptionOption): ?string
    {
        $stdinContent = $this->readStdin();
        if ($stdinContent !== '') {
            // @codeCoverageIgnoreStart
            return $stdinContent;
            // @codeCoverageIgnoreEnd
        }

        return ($descriptionOption !== null && trim($descriptionOption) !== '') ? trim($descriptionOption) : null;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function readStdin(): string
    {
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
     * @param list<string> $extraRequiredFieldIds
     * @param array<string, mixed>|null $descriptionAdf
     *
     * @return array<string, mixed>|null
     */
    protected function promptForExtraRequiredFields(
        bool $interactive,
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        string $summary,
        ?array $descriptionAdf,
        array $extraRequiredFieldIds
    ): ?array {
        return $this->promptService->promptForExtraRequiredFields(
            $interactive,
            $projectKey,
            $issueTypeId,
            $typeExplicitlyProvided,
            $summary,
            $descriptionAdf,
            $extraRequiredFieldIds,
        );
    }

    /**
     * @param array<string, mixed> $allFields
     *
     * @return array<string, mixed>|null
     */
    protected function promptIssueTypeValue(
        string $projectKey,
        string $issueTypeId,
        bool $typeExplicitlyProvided,
        array $allFields,
    ): ?array {
        return $this->promptService->promptIssueTypeValue($projectKey, $issueTypeId, $typeExplicitlyProvided, $allFields);
    }

    protected function chooseIssueTypeInteractively(string $projectKey): ?string
    {
        return $this->promptService->chooseIssueTypeInteractively($projectKey);
    }

    /**
     * @param array<string, mixed>|null $descriptionAdf
     *
     * @return array<string, mixed>|string|null
     */
    protected function promptDescriptionValue(?array $descriptionAdf): array|string|null
    {
        return $this->promptService->promptDescriptionValue($descriptionAdf);
    }
}
