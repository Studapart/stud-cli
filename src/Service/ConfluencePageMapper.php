<?php

declare(strict_types=1);

namespace App\Service;

class ConfluencePageMapper
{
    /**
     * @param array<string, mixed> $data
     * @return array{id: string, title: string, _links: array{webui: string}}
     */
    public function mapPage(array $data): array
    {
        $links = $data['_links'] ?? [];
        $webui = is_string($links['webui'] ?? null) ? $links['webui'] : '';

        return [
            'id' => (string) ($data['id'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            '_links' => ['webui' => $webui],
        ];
    }

    /**
     * Page fields plus current version number and spaceId (top-level or nested under space).
     *
     * @param array<string, mixed> $data
     * @return array{id: string, title: string, version: array{number: int}, spaceId: string|null, _links: array{webui: string}}
     */
    public function mapPageWithVersion(array $data): array
    {
        $mapped = $this->mapPage($data);
        $mapped['version'] = [
            'number' => (int) ($data['version']['number'] ?? 0),
        ];
        $mapped['spaceId'] = isset($data['spaceId']) ? (string) $data['spaceId'] : null;
        if ($mapped['spaceId'] === null && isset($data['space']['id'])) {
            $mapped['spaceId'] = (string) $data['space']['id'];
        }

        return $mapped;
    }

    /**
     * Extract ADF body from Confluence v2 page response (body.atlas_doc_format.value).
     *
     * @param array<string, mixed> $data
     * @return array{type: string, content?: array<int, mixed>}
     */
    public function extractBodyAdf(array $data): array
    {
        $body = $data['body'] ?? null;
        if (! is_array($body)) {
            return ['type' => 'doc', 'content' => []];
        }
        $adf = $body['atlas_doc_format'] ?? null;
        if (! is_array($adf)) {
            return ['type' => 'doc', 'content' => []];
        }
        $value = $adf['value'] ?? null;
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return $this->normalizeBodyAdf($decoded);
        }
        if (is_array($value)) {
            return $this->normalizeBodyAdf($value);
        }

        return ['type' => 'doc', 'content' => []];
    }

    /**
     * @return array{type: string, content?: array<int, mixed>}
     */
    protected function normalizeBodyAdf(mixed $adf): array
    {
        if (! is_array($adf) || ! isset($adf['type'])) {
            return ['type' => 'doc', 'content' => []];
        }
        $content = isset($adf['content']) && is_array($adf['content']) ? $adf['content'] : [];

        return ['type' => (string) $adf['type'], 'content' => $content];
    }
}
