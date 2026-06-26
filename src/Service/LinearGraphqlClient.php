<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Low-level Linear GraphQL HTTP client (SCI-165).
 */
final class LinearGraphqlClient
{
    public function __construct(
        private readonly HttpClientInterface $client,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed> GraphQL `data` node only
     *
     * @throws ApiException on HTTP != 2xx, GraphQL errors[], or missing data
     */
    public function query(string $query, array $variables = []): array
    {
        $response = $this->client->request('POST', '', [
            'json' => [
                'query' => $query,
                'variables' => $variables,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode === 401) {
            throw new ApiException(
                'Invalid or missing LINEAR_API_KEY.',
                $this->extractTechnicalDetails($response),
                401,
            );
        }

        if ($statusCode !== 200) {
            throw new ApiException(
                'Linear GraphQL request failed.',
                $this->extractTechnicalDetails($response),
                $statusCode,
            );
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (\Throwable) {
            throw new ApiException(
                'Linear GraphQL request returned invalid JSON.',
                $this->extractTechnicalDetails($response),
            );
        }

        if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            /** @var list<mixed> $graphQlErrors */
            $graphQlErrors = array_values($payload['errors']);

            throw new ApiException(
                'Linear GraphQL request failed.',
                $this->formatGraphQlErrors($graphQlErrors),
            );
        }

        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            throw new ApiException(
                'Linear GraphQL request returned no data.',
                $this->extractTechnicalDetails($response),
            );
        }

        return $payload['data'];
    }

    /**
     * @param list<mixed> $errors
     */
    private function formatGraphQlErrors(array $errors): string
    {
        $parts = [];
        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $segment = isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : 'Unknown GraphQL error';

            if (isset($error['extensions']) && $error['extensions'] !== []) {
                $encoded = json_encode($error['extensions'], JSON_UNESCAPED_UNICODE);
                if (is_string($encoded) && $encoded !== '[]' && $encoded !== '{}') {
                    $segment .= ' (' . $encoded . ')';
                }
            }

            $parts[] = $segment;
        }

        if ($parts === []) {
            return 'Linear GraphQL request failed.';
        }

        return implode('; ', $parts);
    }

    private function extractTechnicalDetails(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (\Throwable) {
            return 'HTTP ' . $response->getStatusCode();
        }
    }
}
