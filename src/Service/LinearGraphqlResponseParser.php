<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use App\Exception\IssueTrackerException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Turns a Linear GraphQL HTTP response into its `data` node or a typed exception.
 */
class LinearGraphqlResponseParser
{
    /**
     * @return array<string, mixed> GraphQL `data` node only
     *
     * @throws ApiException on HTTP != 2xx, GraphQL errors[], or missing data
     */
    public function parse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode === 401) {
            throw IssueTrackerException::missingLinearApiKey();
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

        return $this->extractData($payload, $response);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws ApiException on GraphQL errors[] or missing data
     */
    protected function extractData(array $payload, ResponseInterface $response): array
    {
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
    protected function formatGraphQlErrors(array $errors): string
    {
        $parts = [];
        foreach ($errors as $error) {
            if (is_array($error)) {
                $parts[] = $this->formatGraphQlError($error);
            }
        }

        if ($parts === []) {
            return 'Linear GraphQL request failed.';
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<mixed> $error
     */
    protected function formatGraphQlError(array $error): string
    {
        $segment = isset($error['message']) && is_string($error['message'])
            ? $error['message']
            : 'Unknown GraphQL error';

        if (! isset($error['extensions']) || $error['extensions'] === []) {
            return $segment;
        }

        $encoded = json_encode($error['extensions'], JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && $encoded !== '[]' && $encoded !== '{}') {
            $segment .= ' (' . $encoded . ')';
        }

        return $segment;
    }

    protected function extractTechnicalDetails(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (\Throwable) {
            return 'HTTP ' . $response->getStatusCode();
        }
    }
}
