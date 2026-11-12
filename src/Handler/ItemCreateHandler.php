<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\ExtraFieldPromptContext;
use App\DTO\IssueCreateContent;
use App\DTO\IssueCreationState;
use App\DTO\ItemCreateInput;
use App\DTO\MessageRef;
use App\Exception\ApiException;
use App\Exception\IssueTrackerException;
use App\Exception\LinearTypeLabelException;
use App\Exception\StudConfigException;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Response\ItemCreateResponse;
use App\Service\IssueTrackerLabelGroupsCapable;
use App\Service\IssueTrackerPort;
use App\Service\ItemCreateProjectResolver;
use App\Service\StudIssueKeys;

class ItemCreateHandler implements JiraAware
{
    public function __construct(
        private readonly ItemCreateProjectResolver $projectResolver,
        private readonly ItemCreatePrompts $prompts,
        private readonly IssueTrackerPort $provider,
        private readonly ItemCreateFieldFlow $fieldFlow,
    ) {
    }

    public function handle(bool $interactive, ItemCreateInput $input): ItemCreateResponse
    {
        $projectKeyOrError = $this->resolveProjectKeyOrError($interactive, $input->project);
        if ($projectKeyOrError instanceof ItemCreateResponse) {
            return $projectKeyOrError;
        }
        $projectKey = $projectKeyOrError;
        $type = $this->fieldFlow->fields->resolveIssueTypeName($input->type, $input->parentKey);

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

        $skippedOptionalFields = $this->fieldFlow->options->apply($stateOrError, $input);

        return $this->createIssueAndReturnResponse($stateOrError->fields, $skippedOptionalFields, $stateOrError);
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

        $issueTypeId = $this->fieldFlow->fields->resolveIssueTypeId($projectKey, $type);
        if ($issueTypeId === null) {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_createmeta', ['error' => "Issue type \"{$type}\" not found for project \"{$projectKey}\""]));
        }

        try {
            $allFieldsMeta = $this->provider->getCreateMetaFields($projectKey, $issueTypeId);
        } catch (\Throwable) {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_createmeta', ['error' => 'Could not fetch field metadata']));
        }

        $description = $this->getDescription($input->descriptionOption);
        $requiredFieldIds = $this->fieldFlow->fields->getRequiredFieldIdsFromMeta($allFieldsMeta);
        $fields = $this->fieldFlow->fields->buildBaseFields(
            $projectKey,
            $issueTypeId,
            new IssueCreateContent($summary, $description, $input->descriptionFormat, $input->parentKey),
        );

        return new IssueCreationState($projectKey, $issueTypeId, $allFieldsMeta, $requiredFieldIds, $fields);
    }

    protected function resolveLabelGroupsCapableCreateMetadata(
        string $projectKey,
        string $type,
        string $summary,
        ItemCreateInput $input,
    ): IssueCreationState|ItemCreateResponse {
        $linearScopeKey = $this->projectResolver->resolveLinearScopeKey($projectKey) ?? $projectKey;

        try {
            $allFieldsMeta = $this->provider->getCreateMetaFields($linearScopeKey, $type);
        } catch (\Throwable) {
            return ItemCreateResponse::error(MessageRef::key('item.create.error_createmeta', ['error' => 'Could not fetch field metadata']));
        }

        $description = $this->getDescription($input->descriptionOption);
        $fields = [
            StudIssueKeys::PROJECT => [StudIssueKeys::KEY => $linearScopeKey],
            StudIssueKeys::ISSUE_TYPE => [StudIssueKeys::NAME => $type],
            StudIssueKeys::TITLE => $summary,
        ];

        if ($description !== null && $description !== '') {
            $format = ($input->descriptionFormat !== null && trim($input->descriptionFormat) !== '')
                ? trim($input->descriptionFormat)
                : 'plain';
            $fields[StudIssueKeys::DESCRIPTION] = $this->provider->formatDescription($description, $format);
        }

        if ($input->parentKey !== null && trim($input->parentKey) !== '') {
            $fields[StudIssueKeys::PARENT] = [StudIssueKeys::KEY => trim($input->parentKey)];
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
        $descriptionAdf = $state->fields[StudIssueKeys::DESCRIPTION] ?? null;

        $fieldValues = [
            'projectKey' => $state->projectKey,
            'issueTypeId' => $state->issueTypeId,
            'title' => (string) $state->fields[StudIssueKeys::TITLE],
            'descriptionAdf' => $descriptionAdf,
            'assigneeOption' => null,
            'parentKey' => $parentKeyTrimmed,
        ];
        $fillIssueType = $typeExplicitlyProvided || ! $interactive;
        $extraRequired = $this->fieldFlow->fields->resolveStandardFieldsAndExtraRequired($state, $fieldValues, $fillIssueType);
        $this->fieldFlow->fields->defaultAssigneeWhenFieldPresent($state->allFieldsMeta, $state->fields, null);

        if ($extraRequired === []) {
            return null;
        }

        return $this->promptAndMergeExtraFields(
            $interactive,
            new ExtraFieldPromptContext($state, $typeExplicitlyProvided, $descriptionAdf),
            $extraRequired
        );
    }

    /**
     * @param list<string> $extraRequired
     */
    protected function promptAndMergeExtraFields(
        bool $interactive,
        ExtraFieldPromptContext $context,
        array $extraRequired
    ): ?ItemCreateResponse {
        $state = $context->state;
        $prompted = $this->prompts->service->promptForExtraRequiredFields($interactive, $context, $extraRequired);
        if ($prompted === null) {
            $fieldsList = $this->fieldFlow->fields->getExtraRequiredFieldsList(
                $state->projectKey,
                $state->issueTypeId,
                $extraRequired
            );

            return ItemCreateResponse::error(MessageRef::key('item.create.error_extra_required', ['fields' => $fieldsList]));
        }
        foreach ($prompted as $fieldId => $value) {
            $state->fields[$this->fieldFlow->fields->getCreatePayloadFieldKey((string) $fieldId)] = $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $skippedOptionalFields
     */
    protected function createIssueAndReturnResponse(
        array $fields,
        array $skippedOptionalFields,
        IssueCreationState $state,
    ): ItemCreateResponse {
        if (! $this->provider instanceof IssueTrackerLabelGroupsCapable
            && $this->fieldFlow->jira->isJiraBacked($this->provider)) {
            return $this->fieldFlow->deferral->create($this->provider, $state, $skippedOptionalFields);
        }

        return $this->createDirectly($fields, $skippedOptionalFields);
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $skippedOptionalFields
     */
    protected function createDirectly(array $fields, array $skippedOptionalFields): ItemCreateResponse
    {
        try {
            $result = $this->provider->create($fields);

            return ItemCreateResponse::success($result['key'], $result['self'], $skippedOptionalFields);
        } catch (LinearTypeLabelException $e) {
            return ItemCreateResponse::error($e->messageRef);
        } catch (StudConfigException $e) {
            return ItemCreateResponse::error($e->messageRef);
        } catch (IssueTrackerException $e) {
            return ItemCreateResponse::error($e->messageRef);
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

        return $this->prompts->prompt->ask(MessageRef::key('item.create.prompt_summary'));
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
        return $this->prompts->service->promptIssueTypeValue($projectKey, $issueTypeId, $typeExplicitlyProvided, $allFields);
    }

    protected function chooseIssueTypeInteractively(string $projectKey): ?string
    {
        return $this->prompts->service->chooseIssueTypeInteractively($projectKey);
    }

    /**
     * @param array<string, mixed>|null $descriptionAdf
     *
     * @return array<string, mixed>|string|null
     */
    protected function promptDescriptionValue(?array $descriptionAdf): array|string|null
    {
        return $this->prompts->service->promptDescriptionValue($descriptionAdf);
    }
}
