<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\ItemUpdateInput;
use App\Exception\ApiException;
use App\Response\ItemUpdateResponse;
use App\Service\FieldsParser;
use App\Service\JiraService;
use App\Service\TranslationService;

class ItemUpdateHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator,
        private readonly FieldsParser $fieldsParser
    ) {
    }

    public function handle(ItemUpdateInput $input): ItemUpdateResponse
    {
        $fields = [];
        $skipped = [];

        if ($input->summary !== null && trim($input->summary) !== '') {
            $fields['summary'] = trim($input->summary);
        }
        $this->applyDescription($input, $fields);
        $parsedFields = $this->resolveParsedFields($input);

        if ($fields === [] && $parsedFields === []) {
            return ItemUpdateResponse::error($this->translator->trans('item.update.error_no_fields'));
        }

        $editMetaOrError = $this->fetchEditMeta($input->key);
        if ($editMetaOrError instanceof ItemUpdateResponse) {
            return $editMetaOrError;
        }

        if ($parsedFields !== []) {
            $result = $this->fieldsParser->matchAndTransform($parsedFields, $editMetaOrError);
            foreach ($result['matched'] as $key => $value) {
                $fields[$key] = $value;
            }
            $skipped = $result['unmatched'];
        }

        return $this->sendUpdate($input->key, $fields, $skipped);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function applyDescription(ItemUpdateInput $input, array &$fields): void
    {
        $desc = $input->descriptionOption;
        if ($desc === null || trim($desc) === '') {
            return;
        }
        $format = ($input->descriptionFormat !== null && trim($input->descriptionFormat) !== '')
            ? trim($input->descriptionFormat)
            : 'plain';
        $fields['description'] = $this->jiraService->descriptionToAdf(trim($desc), $format);
    }

    /**
     * @return array<string, string|list<string>>
     */
    protected function resolveParsedFields(ItemUpdateInput $input): array
    {
        if ($input->fieldsMap !== null) {
            return $input->fieldsMap;
        }
        if ($input->fieldsOption !== null && trim($input->fieldsOption) !== '') {
            return $this->fieldsParser->parse($input->fieldsOption);
        }

        return [];
    }

    /**
     * @return array<string, array{required: bool, name: string}>|ItemUpdateResponse
     */
    protected function fetchEditMeta(string $key): array|ItemUpdateResponse
    {
        try {
            return $this->jiraService->getEditMetaFields($key);
        } catch (ApiException $e) {
            $detail = $e->getTechnicalDetails();
            $error = $detail !== '' ? $e->getMessage() . ' ' . $detail : $e->getMessage();

            return ItemUpdateResponse::error($this->translator->trans('item.update.error_editmeta', ['error' => $error]));
        } catch (\Throwable $e) {
            return ItemUpdateResponse::error($this->translator->trans('item.update.error_editmeta', ['error' => $e->getMessage()]));
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $skipped
     */
    protected function sendUpdate(string $key, array $fields, array $skipped): ItemUpdateResponse
    {
        try {
            $this->jiraService->updateIssue($key, $fields);

            return ItemUpdateResponse::success($key, $skipped);
        } catch (ApiException $e) {
            $detail = $e->getTechnicalDetails();
            $error = $detail !== '' ? $e->getMessage() . ' ' . $detail : $e->getMessage();

            return ItemUpdateResponse::error($this->translator->trans('item.update.error_update', ['error' => $error]));
        } catch (\Throwable $e) {
            return ItemUpdateResponse::error($this->translator->trans('item.update.error_update', ['error' => $e->getMessage()]));
        }
    }
}
