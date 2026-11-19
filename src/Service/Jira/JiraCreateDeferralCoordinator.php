<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\DTO\IssueCreationState;
use App\DTO\JiraCreateRetry;
use App\DTO\MessageRef;
use App\Exception\ApiException;
use App\Response\ItemCreateResponse;
use App\Service\IssueTrackerPort;
use App\Service\StudIssueKeys;

final class JiraCreateDeferralCoordinator
{
    /** @var list<string> */
    private const IMMUTABLE = [
        StudIssueKeys::PROJECT,
        StudIssueKeys::ISSUE_TYPE,
        StudIssueKeys::TITLE,
        StudIssueKeys::DESCRIPTION,
        StudIssueKeys::PARENT,
        'summary',
        'issuetype',
    ];

    /** @var array<string, string> */
    private const JIRA_TO_STUD = [
        'summary' => StudIssueKeys::TITLE,
        'issuetype' => StudIssueKeys::ISSUE_TYPE,
    ];

    public function __construct(
        private readonly JiraCreateDeferErrorParser $errorParser,
        private readonly JiraIssueFieldWriter $fieldWriter,
    ) {
    }

    /**
     * @param list<string> $skippedOptionalFields
     */
    public function create(
        IssueTrackerPort $port,
        IssueCreationState $state,
        array $skippedOptionalFields,
    ): ItemCreateResponse {
        try {
            $created = $port->create($state->fields);

            return ItemCreateResponse::success($created['key'], $created['self'], $skippedOptionalFields);
        } catch (ApiException $exception) {
            return $this->retryOrFail($port, $state, $skippedOptionalFields, $exception);
        } catch (\Throwable $exception) {
            return $this->createError($exception->getMessage());
        }
    }

    /**
     * @param list<string> $skippedOptionalFields
     */
    private function retryOrFail(
        IssueTrackerPort $port,
        IssueCreationState $state,
        array $skippedOptionalFields,
        ApiException $exception,
    ): ItemCreateResponse {
        $errors = $this->errorParser->parseDeferrableFieldErrors($exception);
        if ($errors === null) {
            return $this->createError($this->formatException($exception));
        }

        $stripKeys = $this->studKeys(array_keys($errors), $state->fields);
        if ($this->containsImmutable($stripKeys)) {
            return $this->createError($this->formatException($exception));
        }
        $requiredName = $this->requiredFieldName($stripKeys, $state->payloadKeyToInputKey, $state->allFieldsMeta);
        if ($requiredName !== null) {
            return ItemCreateResponse::error(MessageRef::key(
                'item.create.error_required_on_create_screen',
                ['fields' => $requiredName],
            ));
        }

        return $this->retryCreate($port, $state, $skippedOptionalFields, new JiraCreateRetry($stripKeys, $exception));
    }

    /**
     * @param list<string> $skippedOptionalFields
     */
    private function retryCreate(
        IssueTrackerPort $port,
        IssueCreationState $state,
        array $skippedOptionalFields,
        JiraCreateRetry $retry,
    ): ItemCreateResponse {
        $stripKeys = $retry->stripKeys;
        $retryFields = $state->fields;
        foreach ($stripKeys as $key) {
            unset($retryFields[$key]);
        }

        try {
            $created = $port->create($retryFields);
        } catch (\Throwable $second) {
            $secondMessage = $second instanceof ApiException ? $this->formatException($second) : $second->getMessage();

            return $this->retryError($retry->firstFailure, $secondMessage, $stripKeys);
        }

        $raw = $this->autofillValues($stripKeys, $state->fields, $state->payloadKeyToInputKey, $state->allFieldsMeta);
        $deferred = $this->deferredInputs($this->withoutKeys($stripKeys, $raw), $state->fieldInputsForUpdate, $state->payloadKeyToInputKey);
        $warnings = $this->warningNames(
            $this->fieldWriter->applyFields($created['key'], $deferred, $port, $raw),
            $state->allFieldsMeta,
        );

        return ItemCreateResponse::success(
            $created['key'],
            $created['self'],
            $skippedOptionalFields,
            $this->deferredKeys($deferred, $raw),
            $warnings,
        );
    }

    /**
     * @param list<string> $errorKeys
     * @param array<string, mixed> $createFields
     *
     * @return list<string>
     */
    private function studKeys(array $errorKeys, array $createFields): array
    {
        $keys = [];
        foreach ($errorKeys as $errorKey) {
            $studKey = self::JIRA_TO_STUD[$errorKey] ?? $errorKey;
            if (! array_key_exists($studKey, $createFields) && array_key_exists($errorKey, $createFields)) {
                $studKey = $errorKey;
            }
            $keys[] = $studKey;
        }

        return $keys;
    }

    /**
     * @param list<string> $stripKeys
     */
    private function containsImmutable(array $stripKeys): bool
    {
        foreach ($stripKeys as $key) {
            if (in_array($key, self::IMMUTABLE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $stripKeys
     * @param array<string, string> $payloadKeyToInputKey
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     */
    private function requiredFieldName(array $stripKeys, array $payloadKeyToInputKey, array $allFieldsMeta): ?string
    {
        foreach ($stripKeys as $key) {
            $inputKey = $payloadKeyToInputKey[$key] ?? $key;
            $required = ($allFieldsMeta[$key]['required'] ?? false) || ($allFieldsMeta[$inputKey]['required'] ?? false);
            if ($required) {
                return (string) ($allFieldsMeta[$key]['name'] ?? $allFieldsMeta[$inputKey]['name'] ?? $key);
            }
        }

        return null;
    }

    /**
     * @param list<string> $stripKeys
     * @param array<string, string|list<string>> $fieldInputsForUpdate
     * @param array<string, string> $payloadKeyToInputKey
     *
     * @return array<string, string|list<string>>
     */
    private function deferredInputs(array $stripKeys, array $fieldInputsForUpdate, array $payloadKeyToInputKey): array
    {
        $deferred = [];
        $unmapped = false;
        foreach ($stripKeys as $payloadKey) {
            if (! isset($payloadKeyToInputKey[$payloadKey])) {
                $unmapped = true;

                continue;
            }
            $inputKey = $payloadKeyToInputKey[$payloadKey];
            if (array_key_exists($inputKey, $fieldInputsForUpdate)) {
                $deferred[$inputKey] = $fieldInputsForUpdate[$inputKey];
            }
        }

        return $unmapped ? $fieldInputsForUpdate : $deferred;
    }

    /**
     * @param list<string> $stripKeys
     * @param array<string, mixed> $createFields
     * @param array<string, string> $payloadKeyToInputKey
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     *
     * @return array<string, mixed>
     */
    private function autofillValues(array $stripKeys, array $createFields, array $payloadKeyToInputKey, array $allFieldsMeta): array
    {
        $values = [];
        foreach ($stripKeys as $payloadKey) {
            if (isset($payloadKeyToInputKey[$payloadKey]) || ! isset($allFieldsMeta[$payloadKey])) {
                continue;
            }
            if (array_key_exists($payloadKey, $createFields)) {
                $values[$payloadKey] = $createFields[$payloadKey];
            }
        }

        return $values;
    }

    /**
     * @param list<string> $stripKeys
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private function withoutKeys(array $stripKeys, array $raw): array
    {
        $keys = [];
        foreach ($stripKeys as $key) {
            if (! array_key_exists($key, $raw)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $warnings
     * @param array<string, array{required: bool, name: string}> $allFieldsMeta
     *
     * @return list<string>
     */
    private function warningNames(array $warnings, array $allFieldsMeta): array
    {
        $named = [];
        foreach ($warnings as $warning) {
            $named[] = (string) ($allFieldsMeta[$warning]['name'] ?? $warning);
        }

        return $named;
    }

    /**
     * @param array<string, string|list<string>> $deferred
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private function deferredKeys(array $deferred, array $raw): array
    {
        $keys = array_map(static fn (string|int $key): string => (string) $key, array_keys($deferred));
        foreach (array_keys($raw) as $key) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * @param list<string> $stripKeys
     */
    private function retryError(ApiException $firstFailure, string $secondMessage, array $stripKeys): ItemCreateResponse
    {
        return ItemCreateResponse::error(MessageRef::key(
            'item.create.error_create_retry',
            [
                'error' => $this->formatException($firstFailure) . ' | ' . $secondMessage,
                'fields' => implode(', ', $stripKeys),
            ],
        ));
    }

    private function formatException(ApiException $exception): string
    {
        $detail = $exception->getTechnicalDetails();
        if (mb_strlen($detail) > 500) {
            $detail = mb_substr($detail, 0, 500) . '... (truncated)';
        }

        return $detail !== '' ? $exception->getMessage() . ' ' . $detail : $exception->getMessage();
    }

    private function createError(string $error): ItemCreateResponse
    {
        return ItemCreateResponse::error(MessageRef::key('item.create.error_create', ['error' => $error]));
    }
}
