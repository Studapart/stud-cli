<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JiraFieldMetadataService
{
    public function __construct(
        private readonly HttpClientInterface $client,
    ) {
    }

    /**
     * @return array<string, array{required: bool, name: string}>
     */
    public function getCreateMetaFields(string $projectIdOrKey, string $issueTypeId): array
    {
        $url = "/rest/api/3/issue/createmeta/{$projectIdOrKey}/issuetypes/{$issueTypeId}";
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not fetch create field metadata for project \"{$projectIdOrKey}\" and issue type \"{$issueTypeId}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();

        return $this->normalizeCreateMetaFields($data['fields'] ?? []);
    }

    /**
     * @return array<string, array{required: bool, name: string}>
     */
    public function getEditMetaFields(string $issueIdOrKey): array
    {
        $url = "/rest/api/3/issue/{$issueIdOrKey}/editmeta";
        $response = $this->client->request('GET', $url);

        if ($response->getStatusCode() !== 200) {
            throw new ApiException(
                "Could not fetch edit metadata for issue \"{$issueIdOrKey}\".",
                $this->extractTechnicalDetails($response),
                $response->getStatusCode()
            );
        }

        $data = $response->toArray();

        return $this->normalizeFieldMetadata($data['fields'] ?? []);
    }

    /**
     * Createmeta may return a list. Prefer each entry's fieldId or key over the array index.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, array{required: bool, name: string}>
     */
    private function normalizeCreateMetaFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $fieldId => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $id = $meta['fieldId'] ?? $meta['key'] ?? $fieldId;
            $result[(string) $id] = [
                'required' => (bool) ($meta['required'] ?? false),
                'name' => (string) ($meta['name'] ?? $id),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, array{required: bool, name: string}>
     */
    private function normalizeFieldMetadata(array $fields): array
    {
        $result = [];
        foreach ($fields as $fieldId => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $result[(string) $fieldId] = [
                'required' => (bool) ($meta['required'] ?? false),
                'name' => (string) ($meta['name'] ?? $fieldId),
            ];
        }

        return $result;
    }

    protected function extractTechnicalDetails(\Symfony\Contracts\HttpClient\ResponseInterface $response): string
    {
        $statusCode = $response->getStatusCode();
        $responseBody = 'No response body';

        try {
            $content = $response->getContent(false);
            if (! empty($content)) {
                $responseBody = mb_strlen($content) > 500
                    ? mb_substr($content, 0, 500) . '... (truncated)'
                    : $content;
            }
        } catch (\Exception $e) {
            $responseBody = 'Unable to read response body: ' . $e->getMessage();
        }

        return sprintf('HTTP %d: %s', $statusCode, $responseBody);
    }
}
